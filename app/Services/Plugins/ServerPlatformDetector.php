<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Plugins;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * Works out a server's loader and Minecraft version from its files (version_history.json,
 * .fabric, purpur.yml, velocity.toml...), its egg (name and startup command) and its startup
 * variables. Files win over the egg, since they show what really runs. Cached for five
 * minutes per server; one directory listing, plus a small file read on Paper.
 */
final readonly class ServerPlatformDetector
{
    private const int CACHE_SECONDS = 300;

    /** Startup variables that hold the Minecraft version in the common eggs. */
    private const array VERSION_VARIABLES = ['MINECRAFT_VERSION', 'MC_VERSION', 'VERSION', 'GAME_VERSION'];

    /** Egg name and startup hints, most specific first. */
    private const array EGG_HINTS = [
        'neoforge' => 'neoforge',
        'neoforged' => 'neoforge',
        'forge' => 'forge',
        'quilt' => 'quilt',
        'fabric' => 'fabric',
        'folia' => 'folia',
        'purpur' => 'purpur',
        'paper' => 'paper',
        'spigot' => 'spigot',
        'bukkit' => 'bukkit',
        'velocity' => 'velocity',
        'waterfall' => 'waterfall',
        'bungee' => 'bungeecord',
    ];

    public function detect(Server $server): ServerPlatform
    {
        return Cache::remember('plugins:platform:'.$server->uuid, self::CACHE_SECONDS, fn (): ServerPlatform => $this->fresh($server));
    }

    public function forget(Server $server): void
    {
        Cache::forget('plugins:platform:'.$server->uuid);
    }

    private function fresh(Server $server): ServerPlatform
    {
        $from = [];
        [$fileLoader, $fileVersion] = $this->fromFiles($server);
        $eggLoader = $this->fromEgg($server);

        // Forge and NeoForge leave the same files behind; the egg tells them apart.
        $loader = $fileLoader === 'forge' && $eggLoader === 'neoforge' ? 'neoforge' : ($fileLoader ?? $eggLoader);
        if ($fileLoader !== null) {
            $from[] = 'files';
        } elseif ($eggLoader !== null) {
            $from[] = 'egg';
        }

        $version = $fileVersion;
        if ($version !== null) {
            $from[] = 'files';
        } else {
            $version = $this->fromVariables($server);
            if ($version !== null) {
                $from[] = 'variable';
            }
        }

        return new ServerPlatform($loader, $version, array_values(array_unique($from)));
    }

    /** @return array{0: string|null, 1: string|null} */
    private function fromFiles(Server $server): array
    {
        try {
            $entries = Daemon::server($server)->files()->getDirectory('/', 10);
        } catch (DaemonConnectionException) {
            return [null, null];
        }

        $names = [];
        foreach ($entries as $entry) {
            $names[mb_strtolower($entry['name'] ?? '')] = $entry['file'] ?? true;
        }

        $has = fn (string $name): bool => array_key_exists($name, $names);
        $version = null;
        $paperFlavour = null;

        if ($has('version_history.json')) {
            [$paperFlavour, $version] = $this->versionHistory($server);
        }

        $loader = match (true) {
            $has('velocity.toml') => 'velocity',
            $paperFlavour !== null => $paperFlavour,
            $has('purpur.yml') => 'purpur',
            $has('paper.yml'), $has('paper-global.yml') => 'paper',
            $has('spigot.yml') => 'spigot',
            $has('bukkit.yml') => 'bukkit',
            $has('.quilt'), $has('quilt-server-launcher.properties') => 'quilt',
            $has('.fabric'), $has('fabric-server-launcher.properties'), $has('fabric-server-launch.jar') => 'fabric',
            $has('user_jvm_args.txt'), $has('forge-installer.jar') => 'forge',
            $has('waterfall.yml') => 'waterfall',
            default => null,
        };

        return [$loader, $version];
    }

    /**
     * Paper, Purpur and Folia write version_history.json with a line such as
     * "git-Paper-196 (MC: 1.20.1)" or "1.21.4-122-a1b2c3d (MC: 1.21.4)".
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function versionHistory(Server $server): array
    {
        try {
            $content = Daemon::server($server)->files()->getContent('version_history.json', 64 * 1024);
        } catch (DaemonConnectionException|FileSizeTooLargeException) {
            return [null, null];
        }

        try {
            $data = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [null, null];
        }

        $current = Payload::string($data, 'currentVersion');
        $version = preg_match('/\(MC: ([0-9][0-9a-z.\-]*)\)/i', $current, $match) === 1 && ServerPlatform::validGameVersion($match[1]) ? $match[1] : null;
        $lower = mb_strtolower($current);
        $flavour = match (true) {
            str_contains($lower, 'purpur') => 'purpur',
            str_contains($lower, 'folia') => 'folia',
            default => 'paper',
        };

        return [$flavour, $version];
    }

    private function fromEgg(Server $server): ?string
    {
        $server->loadMissing('egg');
        $haystack = mb_strtolower(implode(' ', [$server->egg->name ?? '', $server->startup ?? '']));

        foreach (self::EGG_HINTS as $needle => $loader) {
            if (str_contains($haystack, $needle)) {
                return $loader;
            }
        }

        return null;
    }

    private function fromVariables(Server $server): ?string
    {
        $values = [];
        foreach ($server->resolvedVariables() as $variable) {
            $values[$variable->env_variable] = mb_trim($variable->server_value ?? $variable->default_value);
        }

        foreach (self::VERSION_VARIABLES as $key) {
            if (isset($values[$key]) && ServerPlatform::validGameVersion($values[$key])) {
                return $values[$key];
            }
        }

        return null;
    }
}
