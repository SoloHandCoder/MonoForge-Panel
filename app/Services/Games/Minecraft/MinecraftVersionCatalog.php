<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Games\Minecraft;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Exceptions\Games\MinecraftVersionException;
use Pterodactyl\Services\Plugins\DownloadPolicy;
use Pterodactyl\Services\Plugins\MarketplaceHttp;

/**
 * The versions each Minecraft server software offers, read from the projects' own public APIs
 * (no keys): Mojang's piston-meta manifest, PaperMC Fill v3 (Paper, Folia), Purpur, Fabric meta,
 * Forge's promotions and NeoForge's maven. Lists are cached for an hour; lookups time out fast
 * and fail with a message the page shows.
 *
 * @phpstan-type Version array{id: string, minecraft: string, type: string}
 * @phpstan-type Download array{software: string, version: string, minecraft: string, url: string|null, filename: string|null, hashes: array<string, string>}
 */
final class MinecraftVersionCatalog
{
    public const int CACHE_SECONDS = 3600;

    /** The only hosts a server jar is ever downloaded from. */
    public const array DOWNLOAD_HOSTS = [
        'piston-data.mojang.com',
        'fill-data.papermc.io',
        'api.purpurmc.org',
        'meta.fabricmc.net',
    ];

    /** A version as the APIs spell them: digits, letters, dots, dashes, pluses and underscores. */
    public const string VERSION_PATTERN = '/^[0-9A-Za-z][0-9A-Za-z.+_\-]{0,63}$/';

    private const string MOJANG = 'https://piston-meta.mojang.com/mc/game/version_manifest_v2.json';

    private const string FILL = 'https://fill.papermc.io/v3/projects/';

    private const string PURPUR = 'https://api.purpurmc.org/v2/purpur';

    private const string FABRIC = 'https://meta.fabricmc.net/v2/versions';

    private const string FORGE = 'https://files.minecraftforge.net/net/minecraftforge/forge/promotions_slim.json';

    private const string NEOFORGE = 'https://maven.neoforged.net/api/maven/versions/releases/net/neoforged/neoforge';

    /** Forge's installer layout the stock egg handles starts with 1.17 (run.sh and unix_args.txt). */
    private const string FORGE_MINIMUM = '1.17';

    private const int MAX_VERSIONS = 300;

    /** Whether Wings may be asked to download this URL: HTTPS, an allowed host, no credentials or odd port. */
    public static function allowedUrl(string $url): bool
    {
        return DownloadPolicy::allowedUrl($url, self::DOWNLOAD_HOSTS);
    }

    /**
     * The Minecraft version a NeoForge version is for: "21.4.100-beta" is 1.21.4, "21.0.5" is
     * 1.21, and from the year-based releases on "26.3.0.103" is 26.3. April-fools builds and
     * anything else are skipped.
     */
    public static function neoforgeMinecraft(string $version): ?string
    {
        if (preg_match('/^(\d+)\.(\d+)\.\d+/', $version, $match) !== 1) {
            return null;
        }

        [, $major, $minor] = $match;
        if ((int) $major >= 25) {
            return $major.'.'.$minor;
        }

        if ((int) $major < 20) {
            return null;
        }

        return $minor === '0' ? '1.'.$major : '1.'.$major.'.'.$minor;
    }

    /**
     * Newest first. Snapshots (and pre-releases) only when asked for, on Vanilla and Fabric.
     *
     * @return list<Version>
     *
     * @throws MinecraftVersionException
     */
    public function versions(MinecraftSoftware $software, bool $snapshots = false): array
    {
        $all = Cache::remember('minecraft:versions:'.$software->value, self::CACHE_SECONDS, fn (): array => $this->fetch($software));

        $versions = array_values(array_filter($all, fn (array $version): bool => $snapshots || $version['type'] === 'release'));

        return array_slice($versions, 0, self::MAX_VERSIONS);
    }

    /**
     * What installing a version means: the jar to download (with the hashes the API gives), or,
     * for Forge and NeoForge, only the installer version for the egg's install script.
     *
     * @return Download
     *
     * @throws MinecraftVersionException
     */
    public function resolve(MinecraftSoftware $software, string $version): array
    {
        throw_if(preg_match(self::VERSION_PATTERN, $version) !== 1, MinecraftVersionException::class, 'That version name is not valid.');

        $known = null;
        foreach ($this->versions($software, snapshots: true) as $candidate) {
            if ($candidate['id'] === $version) {
                $known = $candidate;

                break;
            }
        }

        throw_if($known === null, MinecraftVersionException::class, "{$software->label()} has no version {$version}.", 404);

        $download = match ($software) {
            MinecraftSoftware::Vanilla => $this->vanillaJar($version),
            MinecraftSoftware::Paper, MinecraftSoftware::Folia => $this->fillJar($software, $version),
            MinecraftSoftware::Purpur => $this->purpurJar($version),
            MinecraftSoftware::Fabric => $this->fabricJar($version),
            MinecraftSoftware::Forge, MinecraftSoftware::NeoForge => ['url' => null, 'filename' => null, 'hashes' => []],
        };

        throw_if($download['url'] !== null && ! self::allowedUrl($download['url']), MinecraftVersionException::class, 'The download is not on an allowed site, so it was not installed.');

        return ['software' => $software->value, 'version' => $version, 'minecraft' => $known['minecraft'], ...$download];
    }

    /**
     * @return list<Version>
     *
     * @throws MinecraftVersionException
     */
    private function fetch(MinecraftSoftware $software): array
    {
        return match ($software) {
            MinecraftSoftware::Vanilla => $this->vanillaVersions(),
            MinecraftSoftware::Paper, MinecraftSoftware::Folia => $this->fillVersions($software),
            MinecraftSoftware::Purpur => $this->purpurVersions(),
            MinecraftSoftware::Fabric => $this->fabricVersions(),
            MinecraftSoftware::Forge => $this->forgeVersions(),
            MinecraftSoftware::NeoForge => $this->neoforgeVersions(),
        };
    }

    /** @return list<Version> */
    private function vanillaVersions(): array
    {
        $versions = [];
        foreach ($this->list($this->json('Mojang', self::MOJANG), 'versions') as $entry) {
            $id = $this->string($entry, 'id');
            $type = $this->string($entry, 'type');
            if ($id !== null && in_array($type, ['release', 'snapshot'], true) && preg_match(self::VERSION_PATTERN, $id) === 1) {
                $versions[] = ['id' => $id, 'minecraft' => $id, 'type' => $type];
            }
        }

        return $versions;
    }

    /** @return array{url: string|null, filename: string|null, hashes: array<string, string>} */
    private function vanillaJar(string $version): array
    {
        foreach ($this->list($this->json('Mojang', self::MOJANG), 'versions') as $entry) {
            if ($this->string($entry, 'id') !== $version) {
                continue;
            }

            $url = $this->string($entry, 'url');
            throw_if($url === null || ! str_starts_with($url, 'https://piston-meta.mojang.com/'), MinecraftVersionException::class, 'Mojang did not list a download for this version.');

            $meta = $this->json('Mojang', $url);
            $server = is_array($meta['downloads'] ?? null) && is_array($meta['downloads']['server'] ?? null) ? $meta['downloads']['server'] : null;
            $jar = $server === null ? null : $this->string($server, 'url');
            $sha1 = $server === null ? null : $this->string($server, 'sha1');
            throw_if($jar === null, MinecraftVersionException::class, "Mojang has no server download for {$version}.");

            return ['url' => $jar, 'filename' => 'server.jar', 'hashes' => $sha1 === null ? [] : ['sha1' => mb_strtolower($sha1)]];
        }

        throw new MinecraftVersionException("Vanilla has no version {$version}.", 404);
    }

    /** @return list<Version> */
    private function fillVersions(MinecraftSoftware $software): array
    {
        $data = $this->json('PaperMC', self::FILL.$software->value);
        $groups = is_array($data['versions'] ?? null) ? $data['versions'] : [];
        $versions = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group as $id) {
                if (is_string($id) && preg_match(self::VERSION_PATTERN, $id) === 1) {
                    // Paper lists release candidates and pre-releases beside the releases.
                    $versions[] = ['id' => $id, 'minecraft' => $id, 'type' => preg_match('/-(rc|pre)/i', $id) === 1 ? 'snapshot' : 'release'];
                }
            }
        }

        return $this->newestFirst($versions);
    }

    /** @return array{url: string|null, filename: string|null, hashes: array<string, string>} */
    private function fillJar(MinecraftSoftware $software, string $version): array
    {
        $build = $this->json('PaperMC', self::FILL.$software->value.'/versions/'.rawurlencode($version).'/builds/latest');
        $downloads = is_array($build['downloads'] ?? null) ? $build['downloads'] : [];
        $server = is_array($downloads['server:default'] ?? null) ? $downloads['server:default'] : null;
        $url = $server === null ? null : $this->string($server, 'url');
        throw_if($server === null || $url === null, MinecraftVersionException::class, "PaperMC has no build of {$software->label()} {$version} yet.");

        $checksums = is_array($server['checksums'] ?? null) ? $server['checksums'] : [];
        $sha256 = $this->string($checksums, 'sha256');

        return ['url' => $url, 'filename' => 'server.jar', 'hashes' => $sha256 === null ? [] : ['sha256' => mb_strtolower($sha256)]];
    }

    /** @return list<Version> */
    private function purpurVersions(): array
    {
        $versions = [];
        foreach ($this->list($this->json('Purpur', self::PURPUR), 'versions') as $id) {
            if (is_string($id) && preg_match(self::VERSION_PATTERN, $id) === 1) {
                $versions[] = ['id' => $id, 'minecraft' => $id, 'type' => 'release'];
            }
        }

        return $this->newestFirst($versions);
    }

    /** @return array{url: string|null, filename: string|null, hashes: array<string, string>} */
    private function purpurJar(string $version): array
    {
        $latest = $this->json('Purpur', self::PURPUR.'/'.rawurlencode($version).'/latest');
        $build = $this->string($latest, 'build');
        throw_if($build === null || preg_match('/^\d{1,10}$/', $build) !== 1, MinecraftVersionException::class, "Purpur has no build of {$version} yet.");

        // Purpur only publishes an MD5, which the panel does not trust on its own.
        return ['url' => self::PURPUR.'/'.rawurlencode($version).'/'.$build.'/download', 'filename' => 'server.jar', 'hashes' => []];
    }

    /** @return list<Version> */
    private function fabricVersions(): array
    {
        $versions = [];
        foreach ($this->json('Fabric', self::FABRIC.'/game') as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $id = $this->string($entry, 'version');
            if ($id !== null && preg_match(self::VERSION_PATTERN, $id) === 1) {
                $versions[] = ['id' => $id, 'minecraft' => $id, 'type' => ($entry['stable'] ?? false) === true ? 'release' : 'snapshot'];
            }
        }

        return $versions;
    }

    /** @return array{url: string|null, filename: string|null, hashes: array<string, string>} */
    private function fabricJar(string $version): array
    {
        $loader = $this->latestStable($this->json('Fabric', self::FABRIC.'/loader'));
        $installer = $this->latestStable($this->json('Fabric', self::FABRIC.'/installer'));
        throw_if($loader === null || $installer === null, MinecraftVersionException::class, 'Fabric did not list a stable loader and installer.');

        // The server launcher jar downloads the loader and libraries on its first start.
        return [
            'url' => self::FABRIC.'/loader/'.rawurlencode($version).'/'.rawurlencode($loader).'/'.rawurlencode($installer).'/server/jar',
            'filename' => 'server.jar',
            'hashes' => [],
        ];
    }

    /** @return list<Version> */
    private function forgeVersions(): array
    {
        $promos = $this->json('Forge', self::FORGE);
        $promos = is_array($promos['promos'] ?? null) ? $promos['promos'] : [];

        $byMinecraft = [];

        foreach ($promos as $key => $build) {
            if (! is_string($key) || ! is_string($build) || preg_match('/^(\d+\.\d+(?:\.\d+)?)-(recommended|latest)$/', $key, $match) !== 1) {
                continue;
            }

            [, $minecraft, $kind] = $match;
            if (version_compare($minecraft, self::FORGE_MINIMUM, '<') || preg_match('/^[0-9.]{1,32}$/', $build) !== 1) {
                continue;
            }

            // A recommended build wins over the latest one.
            if ($kind === 'recommended' || ! isset($byMinecraft[$minecraft])) {
                $byMinecraft[$minecraft] = $minecraft.'-'.$build;
            }
        }

        $versions = [];
        foreach ($byMinecraft as $minecraft => $id) {
            $versions[] = ['id' => $id, 'minecraft' => $minecraft, 'type' => 'release'];
        }

        return $this->newestFirst($versions, 'minecraft');
    }

    /** @return list<Version> */
    private function neoforgeVersions(): array
    {
        $versions = [];
        foreach ($this->list($this->json('NeoForge', self::NEOFORGE), 'versions') as $id) {
            if (! is_string($id) || preg_match(self::VERSION_PATTERN, $id) !== 1) {
                continue;
            }

            $minecraft = self::neoforgeMinecraft($id);
            if ($minecraft !== null) {
                $versions[] = ['id' => $id, 'minecraft' => $minecraft, 'type' => 'release'];
            }
        }

        return $this->newestFirst($versions);
    }

    /**
     * @param  list<Version>  $versions
     * @return list<Version>
     */
    private function newestFirst(array $versions, string $by = 'id'): array
    {
        usort($versions, fn (array $a, array $b): int => version_compare($b[$by], $a[$by]));

        return $versions;
    }

    /** @param  array<mixed>  $entries */
    private function latestStable(array $entries): ?string
    {
        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['stable'] ?? false) === true) {
                $version = $this->string($entry, 'version');
                if ($version !== null && preg_match(self::VERSION_PATTERN, $version) === 1) {
                    return $version;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function list(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    private function string(mixed $data, string $key): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        $value = $data[$key] ?? null;
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<mixed>
     *
     * @throws MinecraftVersionException
     */
    private function json(string $source, string $url): array
    {
        try {
            $response = Http::withUserAgent(MarketplaceHttp::USER_AGENT)
                ->acceptJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->get($url);
        } catch (ConnectionException) {
            throw MinecraftVersionException::unavailable($source);
        }

        throw_if($response->status() === 404, MinecraftVersionException::class, "{$source} does not have that version.", 404);

        $data = $response->successful() ? $response->json() : null;
        throw_unless(is_array($data), MinecraftVersionException::unavailable($source));

        return $data;
    }
}
