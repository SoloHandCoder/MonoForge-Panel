<?php

declare(strict_types=1);

namespace Pterodactyl\Services\ServerPages;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Contracts\Servers\WakesServers;
use Pterodactyl\Enum\EggSpecificTags;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Services\Query\QueryStatus;
use Pterodactyl\Services\Query\ServerQuery;
use Pterodactyl\Services\Tunnels\TunnelService;
use Pterodactyl\Support\LockedCache;

/**
 * What a published server page shows anyone: never the node, its address or the allocation. The
 * only address on it is the playit tunnel's; without a live tunnel there is none. The payload is
 * built by one request at a time and cached for 30 seconds, so visitors cannot load the game
 * server, Wings or the database.
 *
 * @phpstan-import-type Theme from ServerPageTheme
 *
 * @phpstan-type PublicServerPage array{
 *     slug: string,
 *     name: string,
 *     tagline: string,
 *     description: string,
 *     banner: array{color: string|null, image_url: string|null},
 *     game: string|null,
 *     software: string|null,
 *     version: string|null,
 *     status: 'online'|'offline'|'starting'|'sleeping'|'unknown',
 *     players: array{online: int, max: int}|null,
 *     address: string|null,
 *     join_url: string|null,
 *     votes: int,
 *     can_wake: bool,
 *     theme: Theme,
 *     sections: array{players: bool, votes: bool, address: bool, description: bool},
 *     links: array{discord: string|null, website: string|null},
 * }
 */
final readonly class PublicServerPages
{
    public const int CACHE_SECONDS = 30;

    /** Startup variables a server's version is read from, when the game does not say. */
    private const array VERSION_VARIABLES = ['MINECRAFT_VERSION', 'MC_VERSION', 'VANILLA_VERSION', 'TERRARIA_VERSION', 'VERSION'];

    public function __construct(
        private CacheRepository $cache,
        private ServerQuery $query,
        private ReadsServerState $state,
        private WakesServers $sleep,
        private TunnelService $tunnels,
    ) {}

    /**
     * Which parts of the page the owner shows.
     *
     * @return array{players: bool, votes: bool, address: bool, description: bool}
     */
    public static function sections(ServerPage $page): array
    {
        return [
            'players' => $page->show_players,
            'votes' => $page->show_votes,
            'address' => $page->show_address,
            'description' => $page->show_description,
        ];
    }

    /** The published page at a slug, unless its server is suspended or not installed. */
    public function find(string $slug): ?ServerPage
    {
        if (! ServerPageSlug::valid($slug)) {
            return null;
        }

        $page = ServerPage::query()->where('slug', $slug)->where('published', true)->with('server')->first();
        $server = $page?->server;

        return $server instanceof Server && ! $server->isSuspended() && $server->isInstalled() ? $page : null;
    }

    /**
     * @return PublicServerPage
     */
    public function payload(ServerPage $page): array
    {
        return LockedCache::remember($this->cache, $this->key($page), self::CACHE_SECONDS, fn (): array => $this->build($page));
    }

    /** Drops the cached payload, so a change shows at once. */
    public function forget(ServerPage $page): void
    {
        $this->cache->forget($this->key($page));
    }

    /**
     * Builds the payload of each listed page whose last status is older than $seconds, oldest
     * first, so the discovery page shows a recent status without asking any game server
     * itself. Returns how many were refreshed.
     */
    public function refreshStale(int $seconds, int $limit): int
    {
        $pages = ServerPage::query()
            ->where('published', true)
            ->where('discoverable', true)
            ->where('hidden_from_discovery', false)
            ->where(fn (Builder $query): Builder => $query->whereNull('status_checked_at')->orWhere('status_checked_at', '<', CarbonImmutable::now()->subSeconds($seconds)))
            ->orderByRaw('status_checked_at IS NOT NULL, status_checked_at')
            ->limit($limit)
            ->with('server')
            ->get();

        $count = 0;
        foreach ($pages as $page) {
            $server = $page->server;
            if (! $server instanceof Server || $server->isSuspended() || ! $server->isInstalled()) {
                continue;
            }

            // A payload still cached was built moments ago, and wrote the status then.
            $this->forget($page);
            $this->payload($page);
            $count++;
        }

        return $count;
    }

    private function key(ServerPage $page): string
    {
        return 'monoforge:public:server-page:'.$page->id;
    }

    /**
     * @return PublicServerPage
     */
    private function build(ServerPage $page): array
    {
        $page->refresh();
        $server = $page->server ?? Server::query()->findOrFail($page->server_id);
        $server->loadMissing(['egg.tags', 'node']);

        $game = EggSpecificTags::caseFor($server->tagSlugs());
        [$status, $players, $version] = $this->status($server);
        // A section the owner hides is left out of the payload, not only out of the page.
        $players = $page->show_players ? $players : null;
        $address = $page->show_address ? $this->tunnels->displayAddress($server) : null;

        $this->remember($page, $status, $players);

        return [
            'slug' => $page->slug,
            'name' => $server->name,
            'tagline' => ServerPageText::line($page->tagline, ServerPageText::TAGLINE_MAX),
            'description' => $page->show_description ? ($page->description ?? '') : '',
            'banner' => ['color' => $page->banner_color, 'image_url' => $page->bannerUrl()],
            'game' => $game?->friendlyName(),
            'software' => $server->egg?->name,
            'version' => $version ?? $this->configuredVersion($server),
            'status' => $status,
            'players' => $players,
            'address' => $address,
            'join_url' => $address === null ? null : $this->joinUrl($game, $server->name, $address),
            'votes' => $page->votes,
            'can_wake' => $page->allow_wake && $status === 'sleeping',
            'theme' => ServerPageTheme::for($page),
            'sections' => self::sections($page),
            'links' => ['discord' => ServerPageLinks::discord($page->discord_url), 'website' => ServerPageLinks::website($page->website_url)],
        ];
    }

    /**
     * Keeps the status the page showed on the page row for the discovery page. Written without
     * touching updated_at, which is when the owner last changed the page.
     *
     * @param  array{online: int, max: int}|null  $players
     */
    private function remember(ServerPage $page, string $status, ?array $players): void
    {
        ServerPage::query()->whereKey($page->id)->toBase()->update([
            'last_status' => $status,
            'last_players_online' => $players['online'] ?? null,
            'last_players_max' => $players['max'] ?? null,
            'status_checked_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Sleeping first (no query wakes or waits on a stopped game), then the game's own answer,
     * then the power state Wings reports.
     *
     * @return array{0: 'online'|'offline'|'starting'|'sleeping'|'unknown', 1: array{online: int, max: int}|null, 2: string|null}
     */
    private function status(Server $server): array
    {
        if ($this->sleep->isSleeping($server)) {
            return ['sleeping', null, null];
        }

        $answer = $this->query->status($server);
        if ($answer instanceof QueryStatus) {
            return ['online', ['online' => $answer->online, 'max' => $answer->max], $this->clean($answer->version)];
        }

        try {
            $state = $this->state->read($server)->state;
        } catch (DaemonConnectionException) {
            return ['unknown', null, null];
        }

        return [match ($state) {
            'running' => 'online',
            'starting' => 'starting',
            default => 'offline',
        }, null, null];
    }

    /** The version the owner chose in a startup variable players may see, such as 1.21.4. */
    private function configuredVersion(Server $server): ?string
    {
        $variables = $server->resolvedVariables()
            ->filter(fn (EggVariable $variable): bool => $variable->user_viewable)
            ->keyBy('env_variable');

        foreach (self::VERSION_VARIABLES as $name) {
            $variable = $variables->get($name);
            if (! $variable instanceof EggVariable) {
                continue;
            }

            $override = $variable->getAttribute('server_value');
            $value = $this->clean(is_string($override) && $override !== '' ? $override : $variable->default_value);
            if ($value !== null) {
                return mb_strtolower($value) === 'latest' ? 'Latest' : $value;
            }
        }

        return null;
    }

    /** A short, single-line value without control characters or Minecraft colour codes. */
    private function clean(?string $value): ?string
    {
        $value = mb_trim(preg_replace('/[\x00-\x1F\x7F]|\x{00A7}./u', '', $value ?? '') ?? '');

        return $value === '' ? null : mb_substr($value, 0, 64);
    }

    /**
     * A link that adds the server in the game, where the game has one: Minecraft Bedrock's
     * `minecraft://?addExternalServer=`. Java has no such link, so it gets the address to copy.
     */
    private function joinUrl(?EggSpecificTags $game, string $name, string $address): ?string
    {
        if ($game !== EggSpecificTags::Bedrock) {
            return null;
        }

        [$host, $port] = str_contains($address, ':') ? explode(':', $address, 2) : [$address, '19132'];

        return 'minecraft://?addExternalServer='.rawurlencode(mb_substr(str_replace('|', ' ', $name), 0, 32)).'|'.$host.':'.$port;
    }
}
