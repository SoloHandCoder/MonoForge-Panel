<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Entrance;

use Carbon\CarbonImmutable;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Plugins\ServerPlatformDetector;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Lets a server see players' real IPs behind the shared Minecraft entrance: Gate sends PROXY
 * protocol to a backend, and Paper (and Purpur and Folia, which read the same file) accept it with
 * `proxies.proxy-protocol: true` in config/paper-global.yml. Vanilla, Fabric, Forge and the rest
 * cannot read it and would refuse every join, so they are left alone and Gate never sends it to
 * them.
 *
 * The file is edited through Wings: read, changed in that one key, written back, with the old
 * file kept next to it. Paper reads it when it starts, so the proxy only starts sending PROXY
 * protocol (`mc_proxy_protocol_at`) once the server is stopped or has restarted since the edit.
 * Only run on demand ("Apply to servers") and for new servers, never on every route sync.
 */
class ProxyProtocolApplier
{
    public const string FILE = 'config/paper-global.yml';

    public const string BACKUP = 'config/paper-global.yml.monoforge-backup';

    /** Loaders that read paper-global.yml. */
    public const array LOADERS = ['paper', 'purpur', 'folia'];

    public const string UNSUPPORTED = 'unsupported';

    public const string APPLIED = 'applied';

    public const string RESTART = 'restart';

    public const string FAILED = 'failed';

    public function __construct(private readonly ServerPlatformDetector $platforms, private readonly ReadsServerState $states) {}

    /**
     * The file with `proxies.proxy-protocol: true`; null when it is not YAML the panel can edit
     * safely. A missing file becomes the one key: Paper fills in the rest when it starts.
     */
    public static function enable(string $yaml): ?string
    {
        try {
            $config = mb_trim($yaml) === '' ? [] : Yaml::parse($yaml);
        } catch (ParseException) {
            return null;
        }

        if (! is_array($config)) {
            return null;
        }

        $proxies = $config['proxies'] ?? [];
        if (! is_array($proxies)) {
            return null;
        }

        $proxies['proxy-protocol'] = true;
        $config['proxies'] = $proxies;

        return Yaml::dump($config, 10, 2);
    }

    /**
     * @return self::UNSUPPORTED|self::APPLIED|self::RESTART|self::FAILED
     */
    public function apply(Server $server): string
    {
        try {
            if (! in_array($this->platforms->detect($server)->loader, self::LOADERS, true)) {
                return self::UNSUPPORTED;
            }

            if ($server->mc_proxy_protocol_written_at === null || ! $this->enabledInFile($server)) {
                $this->write($server);
            }

            if ($this->readsIt($server)) {
                $server->forceFill(['mc_proxy_protocol_at' => CarbonImmutable::now()])->saveQuietly();

                return self::APPLIED;
            }

            return self::RESTART;
        } catch (Throwable $throwable) {
            report($throwable);

            return self::FAILED;
        }
    }

    private function enabledInFile(Server $server): bool
    {
        $config = Yaml::parse($this->read($server));

        return is_array($config) && is_array($config['proxies'] ?? null) && ($config['proxies']['proxy-protocol'] ?? false) === true;
    }

    private function write(Server $server): void
    {
        $current = $this->read($server);
        $next = self::enable($current) ?? throw new ParseException('config/paper-global.yml is not YAML the panel can change; set proxies.proxy-protocol by hand.');

        $files = Daemon::server($server)->files();
        if (mb_trim($current) !== '') {
            $files->putContent(self::BACKUP, $current);
        }

        $files->putContent(self::FILE, $next);
        $server->forceFill(['mc_proxy_protocol_written_at' => CarbonImmutable::now(), 'mc_proxy_protocol_at' => null])->saveQuietly();
    }

    private function read(Server $server): string
    {
        try {
            return Daemon::server($server)->files()->getContent(self::FILE, 1024 * 1024);
        } catch (Throwable) {
            // Not there yet: the server never started.
            return '';
        }
    }

    /** Whether the running Paper read the file: the server is stopped, or started after the edit. */
    private function readsIt(Server $server): bool
    {
        $state = $this->states->read($server);
        if (in_array($state->state, ['offline', 'stopped'], true)) {
            return true;
        }

        $written = $server->mc_proxy_protocol_written_at;
        if ($written === null) {
            return false;
        }

        $sinceWrite = (int) $written->diffInMilliseconds(CarbonImmutable::now(), true);

        return $state->uptime > 0 && $state->uptime < $sinceWrite;
    }
}
