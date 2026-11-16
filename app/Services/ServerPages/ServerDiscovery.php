<?php

declare(strict_types=1);

namespace Pterodactyl\Services\ServerPages;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Game;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Models\Tag;
use Pterodactyl\Services\Games\GameDetector;
use Pterodactyl\Services\Games\GameResolver;
use Pterodactyl\Services\Tunnels\TunnelService;
use Pterodactyl\Support\LockedCache;

/**
 * The public server discovery page at /servers: every published page whose owner lists it, an
 * administrator has not hidden, and whose server is installed and not suspended.
 *
 * Built for a weak host: one query for the page of results (plus the eager loads of its
 * servers' eggs, games and tunnels) and no game server is ever asked. The status and players
 * are the ones each page last showed ({@see PublicServerPages}, refreshed on a schedule), and a
 * result is cached for 30 seconds. Like the public page itself, the only address ever listed is
 * a live playit tunnel's.
 *
 * @phpstan-import-type Theme from ServerPageTheme
 *
 * @phpstan-type DiscoveryEntry array{
 *     slug: string,
 *     url: string,
 *     name: string,
 *     tagline: string,
 *     description: string,
 *     game: array{slug: string, name: string, color: string}|null,
 *     status: 'online'|'offline'|'starting'|'sleeping'|'unknown',
 *     players: array{online: int, max: int}|null,
 *     votes: int,
 *     address: string|null,
 *     theme: Theme,
 *     sections: array{players: bool, votes: bool, address: bool, description: bool},
 *     created_at: string|null,
 * }
 * @phpstan-type DiscoveryList array{
 *     data: list<DiscoveryEntry>,
 *     meta: array{page: int, per_page: int, has_more: bool},
 *     games: list<array{slug: string, name: string, color: string}>,
 * }
 */
final readonly class ServerDiscovery
{
    public const int PER_PAGE = 24;

    public const int MAX_PAGE = 200;

    public const int SEARCH_MAX = 64;

    public const int CACHE_SECONDS = 30;

    /** A status older than this is shown as unknown and does not count for "most players". */
    public const int STALE_SECONDS = 900;

    public const array SORTS = ['players', 'votes', 'newest'];

    private const array STATUSES = ['online', 'offline', 'starting', 'sleeping', 'unknown'];

    public function __construct(private CacheRepository $cache, private TunnelService $tunnels) {}

    /**
     * @return DiscoveryList
     */
    public function list(?string $game, string $search, string $sort, int $page, int $perPage = self::PER_PAGE): array
    {
        $search = ServerPageText::line($search, self::SEARCH_MAX);
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'players';
        $page = max(1, min(self::MAX_PAGE, $page));
        $perPage = max(1, min(self::PER_PAGE, $perPage));
        $key = 'monoforge:public:discovery:'.hash('xxh128', (string) json_encode([$game, mb_strtolower($search), $sort, $page, $perPage]));

        return LockedCache::remember($this->cache, $key, self::CACHE_SECONDS, fn (): array => $this->build($game, $search, $sort, $page, $perPage));
    }

    /**
     * @return DiscoveryList
     */
    private function build(?string $game, string $search, string $sort, int $page, int $perPage): array
    {
        $games = Game::query()->orderBy('name')->get(['id', 'slug', 'name', 'color', 'built_in']);
        $query = $this->listed();

        if ($game !== null) {
            $chosen = $games->firstWhere('slug', $game);
            $query->whereIn('servers.egg_id', $chosen instanceof Game ? $this->eggIdsFor($chosen) : []);
        }

        if ($search !== '') {
            $query->where('servers.name', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
        }

        match ($sort) {
            'votes' => $query->orderByDesc('server_pages.votes'),
            'newest' => $query,
            default => $query->orderByRaw(
                'CASE WHEN server_pages.show_players = 1 AND server_pages.status_checked_at >= ? THEN COALESCE(server_pages.last_players_online, 0) ELSE 0 END DESC',
                [CarbonImmutable::now()->subSeconds(self::STALE_SECONDS)->toDateTimeString()],
            )->orderByDesc('server_pages.votes'),
        };

        $rows = $query->orderByDesc('server_pages.id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->with(['server.egg.game', 'server.egg.tags', 'server.tunnel'])
            ->get();

        $entries = [];
        foreach ($rows->take($perPage) as $row) {
            $entries[] = $this->entry($row);
        }

        $options = [];
        foreach ($games as $option) {
            $options[] = ['slug' => $option->slug, 'name' => $option->name, 'color' => $option->color];
        }

        return [
            'data' => $entries,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'has_more' => $rows->count() > $perPage],
            'games' => $options,
        ];
    }

    /**
     * The pages that may be listed, joined to their servers.
     *
     * @return Builder<ServerPage>
     */
    private function listed(): Builder
    {
        return ServerPage::query()
            ->select('server_pages.*')
            ->join('servers', 'servers.id', '=', 'server_pages.server_id')
            ->where('server_pages.published', true)
            ->where('server_pages.discoverable', true)
            ->where('server_pages.hidden_from_discovery', false)
            // Like PublicServerPages::find: never a suspended server or one that is not installed.
            ->where(fn (Builder $status): Builder => $status
                ->whereNull('servers.status')
                ->orWhereNotIn('servers.status', [Server::STATUS_INSTALLING, Server::STATUS_INSTALL_FAILED, Server::STATUS_SUSPENDED]));
    }

    /**
     * The eggs that run a game: those it claims in Admin > Games and, for a built-in game, the
     * unclaimed eggs the panel's detection gives to it (as {@see GameResolver} decides).
     *
     * @return list<int>
     */
    private function eggIdsFor(Game $game): array
    {
        $ids = [];
        foreach (Egg::query()->where('game_id', $game->id)->get(['id']) as $egg) {
            $ids[] = $egg->id;
        }

        if ($game->built_in) {
            foreach (Egg::query()->whereNull('game_id')->with('tags')->get(['id', 'name']) as $egg) {
                $tags = array_values($egg->tags->map(fn (Tag $tag): string => $tag->slug)->all());
                if (GameDetector::detect($egg->name, $tags) === $game->slug) {
                    $ids[] = $egg->id;
                }
            }
        }

        return $ids;
    }

    /**
     * @return DiscoveryEntry
     */
    private function entry(ServerPage $page): array
    {
        /** @var Server $server */
        $server = $page->server;
        $game = GameResolver::forEgg($server->egg);
        $fresh = $page->status_checked_at instanceof CarbonImmutable
            && $page->status_checked_at->greaterThanOrEqualTo(CarbonImmutable::now()->subSeconds(self::STALE_SECONDS));

        $status = $fresh && in_array($page->last_status, self::STATUSES, true) ? $page->last_status : 'unknown';
        $players = $fresh && $page->show_players && $page->last_players_online !== null && $page->last_players_max !== null
            ? ['online' => $page->last_players_online, 'max' => $page->last_players_max]
            : null;

        return [
            'slug' => $page->slug,
            'url' => '/s/'.$page->slug,
            'name' => ServerPageText::line($server->name, ServerPageText::NAME_MAX),
            'tagline' => ServerPageText::line($page->tagline, ServerPageText::TAGLINE_MAX),
            'description' => $page->show_description ? ServerPageText::excerpt($page->description) : '',
            'game' => $game instanceof Game ? ['slug' => $game->slug, 'name' => $game->name, 'color' => $game->color] : null,
            'status' => $status,
            'players' => $players,
            'votes' => $page->votes,
            'address' => $page->show_address ? $this->tunnels->displayAddress($server) : null,
            'theme' => ServerPageTheme::for($page),
            'sections' => PublicServerPages::sections($page),
            'created_at' => $page->created_at?->toAtomString(),
        ];
    }
}
