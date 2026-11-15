<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Plugins\Sources;

use Illuminate\Support\Arr;
use Pterodactyl\Data\Plugins\MarketplaceFile;
use Pterodactyl\Data\Plugins\MarketplaceProject;
use Pterodactyl\Data\Plugins\MarketplaceSearch;
use Pterodactyl\Data\Plugins\MarketplaceSearchResult;
use Pterodactyl\Data\Plugins\MarketplaceVersion;
use Pterodactyl\Enum\MarketplaceProjectType;
use Pterodactyl\Enum\MarketplaceSource;
use Pterodactyl\Services\Plugins\MarketplaceHttp;
use Pterodactyl\Services\Plugins\Payload;
use Pterodactyl\Services\Plugins\ServerPlatform;

/**
 * The CurseForge API (https://docs.curseforge.com/rest-api/), used only once an admin sets an
 * API key (Admin > Settings > Plugins). Mods and Bukkit plugins only: CurseForge modpacks use
 * another format. Files whose author turned off third-party downloads have no download URL
 * and cannot be installed.
 */
final readonly class CurseForgeCatalog implements MarketplaceCatalog
{
    public const string BASE_URL = 'https://api.curseforge.com/v1';

    public const int MINECRAFT = 432;

    public const int CLASS_MODS = 6;

    public const int CLASS_BUKKIT_PLUGINS = 5;

    /** HashAlgo: 1 is SHA-1, 2 is MD5. */
    private const array HASH_ALGORITHMS = [1 => 'sha1', 2 => 'md5'];

    public function __construct(private MarketplaceHttp $http) {}

    public static function apiKey(): string
    {
        // A stored "null" is loaded as null, so the value is read without a type.
        $key = Arr::get(config()->all(), 'monoforge.plugins.curseforge_key');

        return is_string($key) ? mb_trim($key) : '';
    }

    public function source(): MarketplaceSource
    {
        return MarketplaceSource::CurseForge;
    }

    public function available(): bool
    {
        return self::apiKey() !== '';
    }

    public function supports(MarketplaceProjectType $type, ServerPlatform $platform): bool
    {
        return $type !== MarketplaceProjectType::Modpack && ($platform->loader === null || $platform->curseForgeSupported());
    }

    public function search(MarketplaceSearch $search): MarketplaceSearchResult
    {
        return $this->http->remember($this->source(), 'search', $search->cacheKey(), function () use ($search): MarketplaceSearchResult {
            $query = [
                'gameId' => self::MINECRAFT,
                'classId' => $search->type === MarketplaceProjectType::Plugin ? self::CLASS_BUKKIT_PLUGINS : self::CLASS_MODS,
                'searchFilter' => mb_trim($search->query),
                'sortField' => match ($search->sort) {
                    'downloads' => 6,
                    'updated' => 3,
                    'newest' => 11,
                    default => 2,
                },
                'sortOrder' => 'desc',
                'index' => $search->offset(),
                'pageSize' => MarketplaceSearch::PER_PAGE,
            ];

            if ($search->platform->gameVersion !== null) {
                $query['gameVersion'] = $search->platform->gameVersion;

                // CurseForge only filters by loader together with a game version.
                if ($search->type === MarketplaceProjectType::Mod && $search->platform->curseForgeLoader() !== null) {
                    $query['modLoaderType'] = $search->platform->curseForgeLoader();
                }
            }

            $data = $this->http->json($this->source(), 'GET', self::BASE_URL.'/mods/search', array_filter($query, fn (int|string $value): bool => $value !== ''), headers: $this->headers()) ?? [];

            $projects = [];
            foreach (Payload::objects($data, 'data') as $mod) {
                $id = Payload::string($mod, 'id');
                if ($id === '') {
                    continue;
                }

                $authors = Payload::objects($mod, 'authors');
                $icon = Payload::string(Payload::array($mod, 'logo'), 'thumbnailUrl');
                $website = Payload::string(Payload::array($mod, 'links'), 'websiteUrl');
                $projects[] = new MarketplaceProject(
                    source: $this->source(),
                    id: $id,
                    slug: Payload::string($mod, 'slug', $id),
                    title: Payload::string($mod, 'name', $id),
                    description: Payload::string($mod, 'summary'),
                    author: $authors === [] ? null : Payload::nullableString($authors[0], 'name'),
                    iconUrl: str_starts_with($icon, 'https://') ? $icon : null,
                    downloads: Payload::int($mod, 'downloadCount'),
                    updatedAt: Payload::nullableString($mod, 'dateModified'),
                    url: str_starts_with($website, 'https://') ? $website : 'https://www.curseforge.com/minecraft',
                );
            }

            return new MarketplaceSearchResult($projects, min(Payload::int(Payload::array($data, 'pagination'), 'totalCount'), 10_000), $search->page, MarketplaceSearch::PER_PAGE);
        });
    }

    public function versions(string $project, MarketplaceProjectType $type, ServerPlatform $platform): array
    {
        $query = ['pageSize' => 30, 'index' => 0];
        if ($platform->gameVersion !== null) {
            $query['gameVersion'] = $platform->gameVersion;
        }

        if ($type === MarketplaceProjectType::Mod && $platform->curseForgeLoader() !== null) {
            $query['modLoaderType'] = $platform->curseForgeLoader();
        }

        return $this->http->remember($this->source(), 'versions', [$project, $query], function () use ($project, $query): array {
            $data = $this->http->json($this->source(), 'GET', self::BASE_URL.'/mods/'.rawurlencode($project).'/files', $query, headers: $this->headers(), nullOn404: true) ?? [];

            return array_map($this->mapFile(...), Payload::objects($data, 'data'));
        });
    }

    public function version(string $project, string $version, MarketplaceProjectType $type, ?ServerPlatform $platform = null): ?MarketplaceVersion
    {
        $data = $this->http->json($this->source(), 'GET', self::BASE_URL.'/mods/'.rawurlencode($project).'/files/'.rawurlencode($version), headers: $this->headers(), nullOn404: true);
        $file = Payload::array($data, 'data');
        if ($file === [] || Payload::string($file, 'modId') !== $project) {
            return null;
        }

        return $this->mapFile($file);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['x-api-key' => self::apiKey()];
    }

    /** @param  array<mixed>  $file */
    private function mapFile(array $file): MarketplaceVersion
    {
        $hashes = [];
        foreach (Payload::objects($file, 'hashes') as $hash) {
            $algorithm = self::HASH_ALGORITHMS[Payload::int($hash, 'algo')] ?? null;
            $value = mb_strtolower(Payload::string($hash, 'value'));
            if ($algorithm !== null && preg_match('/^[0-9a-f]+$/', $value) === 1) {
                $hashes[$algorithm] = $value;
            }
        }

        $url = Payload::string($file, 'downloadUrl');
        $fileName = Payload::string($file, 'fileName');
        $gameVersions = [];
        $loaders = [];
        foreach (Payload::strings($file, 'gameVersions') as $value) {
            if (ServerPlatform::validGameVersion($value)) {
                $gameVersions[] = $value;
            } elseif (in_array(mb_strtolower($value), ServerPlatform::loaders(), true)) {
                $loaders[] = mb_strtolower($value);
            }
        }

        return new MarketplaceVersion(
            id: Payload::string($file, 'id'),
            projectId: Payload::string($file, 'modId'),
            name: Payload::string($file, 'displayName', $fileName),
            versionNumber: Payload::string($file, 'displayName', $fileName),
            gameVersions: $gameVersions,
            loaders: $loaders,
            channel: match (Payload::int($file, 'releaseType')) {
                2 => 'beta',
                3 => 'alpha',
                default => 'release',
            },
            publishedAt: Payload::nullableString($file, 'fileDate'),
            file: $url === '' || ! str_ends_with(mb_strtolower($fileName), '.jar') ? null : new MarketplaceFile($url, $fileName, Payload::int($file, 'fileLength'), $hashes),
        );
    }
}
