<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Plugins\Sources;

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
 * Modrinth's API v2 (https://docs.modrinth.com/api/). No key is needed. Plugins and mods are
 * both "mod" projects there, told apart by their loader categories.
 */
final readonly class ModrinthCatalog implements MarketplaceCatalog
{
    public const string BASE_URL = 'https://api.modrinth.com/v2';

    /** Search sorts the panel offers, mapped to Modrinth's index names. */
    private const array SORTS = ['relevance' => 'relevance', 'downloads' => 'downloads', 'updated' => 'updated', 'newest' => 'newest'];

    public function __construct(private MarketplaceHttp $http) {}

    public function source(): MarketplaceSource
    {
        return MarketplaceSource::Modrinth;
    }

    public function available(): bool
    {
        return true;
    }

    public function supports(MarketplaceProjectType $type, ServerPlatform $platform): bool
    {
        return true;
    }

    public function search(MarketplaceSearch $search): MarketplaceSearchResult
    {
        return $this->http->remember($this->source(), 'search', $search->cacheKey(), function () use ($search): MarketplaceSearchResult {
            $facets = [[$search->type === MarketplaceProjectType::Modpack ? 'project_type:modpack' : 'project_type:mod']];

            $loaders = $search->type === MarketplaceProjectType::Modpack
                ? ($search->platform->type() === MarketplaceProjectType::Mod ? $search->platform->compatibleLoaders() : [])
                : $search->platform->compatibleLoaders();
            if ($loaders === [] && $search->type !== MarketplaceProjectType::Modpack) {
                $loaders = $search->type === MarketplaceProjectType::Plugin ? ServerPlatform::PLUGIN_LOADERS : ServerPlatform::MOD_LOADERS;
            }

            if ($loaders !== []) {
                $facets[] = array_map(fn (string $loader): string => 'categories:'.$loader, $loaders);
            }

            if ($search->platform->gameVersion !== null) {
                $facets[] = ['versions:'.$search->platform->gameVersion];
            }

            $data = $this->http->json($this->source(), 'GET', self::BASE_URL.'/search', [
                'query' => mb_trim($search->query),
                'facets' => json_encode($facets, JSON_THROW_ON_ERROR),
                'index' => self::SORTS[$search->sort] ?? 'relevance',
                'offset' => $search->offset(),
                'limit' => MarketplaceSearch::PER_PAGE,
            ]) ?? [];

            $projects = [];
            foreach (Payload::objects($data, 'hits') as $hit) {
                $id = Payload::string($hit, 'project_id');
                if ($id === '') {
                    continue;
                }

                $slug = Payload::string($hit, 'slug', $id);
                $type = Payload::string($hit, 'project_type', 'mod');
                $projects[] = new MarketplaceProject(
                    source: $this->source(),
                    id: $id,
                    slug: $slug,
                    title: Payload::string($hit, 'title', $slug),
                    description: Payload::string($hit, 'description'),
                    author: Payload::nullableString($hit, 'author'),
                    iconUrl: $this->httpsUrl(Payload::string($hit, 'icon_url')),
                    downloads: Payload::int($hit, 'downloads'),
                    updatedAt: Payload::nullableString($hit, 'date_modified'),
                    url: 'https://modrinth.com/'.rawurlencode($type === 'modpack' ? 'modpack' : ($search->type === MarketplaceProjectType::Plugin ? 'plugin' : 'mod')).'/'.rawurlencode($slug),
                    clientOnly: Payload::string($hit, 'server_side') === 'unsupported',
                );
            }

            return new MarketplaceSearchResult($projects, min(Payload::int($data, 'total_hits'), 10_000), $search->page, MarketplaceSearch::PER_PAGE);
        });
    }

    public function versions(string $project, MarketplaceProjectType $type, ServerPlatform $platform): array
    {
        $query = [];
        if ($type !== MarketplaceProjectType::Modpack) {
            if ($platform->compatibleLoaders() !== []) {
                $query['loaders'] = json_encode($platform->compatibleLoaders(), JSON_THROW_ON_ERROR);
            }

            if ($platform->gameVersion !== null) {
                $query['game_versions'] = json_encode([$platform->gameVersion], JSON_THROW_ON_ERROR);
            }
        }

        return $this->http->remember($this->source(), 'versions', [$project, $type->value, $query], function () use ($project, $type, $query): array {
            $data = $this->http->json($this->source(), 'GET', self::BASE_URL.'/project/'.rawurlencode($project).'/version', $query, nullOn404: true) ?? [];

            $versions = [];
            foreach (array_slice(Payload::objects($data), 0, 30) as $version) {
                $versions[] = $this->mapVersion($version, $type);
            }

            return $versions;
        });
    }

    public function version(string $project, string $version, MarketplaceProjectType $type, ?ServerPlatform $platform = null): ?MarketplaceVersion
    {
        $data = $this->http->json($this->source(), 'GET', self::BASE_URL.'/version/'.rawurlencode($version), nullOn404: true);
        if ($data === null) {
            return null;
        }

        $mapped = $this->mapVersion($data, $type);

        return $mapped->projectId === $project ? $mapped : null;
    }

    /**
     * The versions the given sha1 hashes are files of, keyed by hash. Unknown files are left out.
     *
     * @param  list<string>  $hashes
     * @return array<string, MarketplaceVersion>
     */
    public function versionsByHash(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $data = $this->http->json($this->source(), 'POST', self::BASE_URL.'/version_files', json: ['hashes' => $hashes, 'algorithm' => 'sha1']) ?? [];

        return $this->mapHashMap($data, $hashes);
    }

    /**
     * The newest version for the platform of each project the given sha1 hashes belong to, keyed by hash.
     *
     * @param  list<string>  $hashes
     * @return array<string, MarketplaceVersion>
     */
    public function latestByHash(array $hashes, ServerPlatform $platform): array
    {
        if ($hashes === []) {
            return [];
        }

        $body = ['hashes' => $hashes, 'algorithm' => 'sha1'];
        if ($platform->compatibleLoaders() !== []) {
            $body['loaders'] = $platform->compatibleLoaders();
        }

        if ($platform->gameVersion !== null) {
            $body['game_versions'] = [$platform->gameVersion];
        }

        $data = $this->http->json($this->source(), 'POST', self::BASE_URL.'/version_files/update', json: $body) ?? [];

        return $this->mapHashMap($data, $hashes);
    }

    /**
     * Titles and icons of the given projects, keyed by project id.
     *
     * @param  list<string>  $ids
     * @return array<string, array{title: string, slug: string, icon_url: string|null, project_type: string}>
     */
    public function projects(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        sort($ids);

        return $this->http->remember($this->source(), 'projects', $ids, function () use ($ids): array {
            $data = $this->http->json($this->source(), 'GET', self::BASE_URL.'/projects', ['ids' => json_encode($ids, JSON_THROW_ON_ERROR)]) ?? [];

            $projects = [];
            foreach (Payload::objects($data) as $project) {
                $id = Payload::string($project, 'id');
                if ($id !== '') {
                    $projects[$id] = [
                        'title' => Payload::string($project, 'title', $id),
                        'slug' => Payload::string($project, 'slug', $id),
                        'icon_url' => $this->httpsUrl(Payload::string($project, 'icon_url')),
                        'project_type' => Payload::string($project, 'project_type', 'mod'),
                    ];
                }
            }

            return $projects;
        });
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $hashes
     * @return array<string, MarketplaceVersion>
     */
    private function mapHashMap(array $data, array $hashes): array
    {
        $versions = [];
        foreach ($hashes as $hash) {
            $version = $data[$hash] ?? null;
            if (is_array($version) && Payload::string($version, 'id') !== '') {
                $versions[$hash] = $this->mapVersion($version, MarketplaceProjectType::Mod);
            }
        }

        return $versions;
    }

    /** @param  array<mixed>  $version */
    private function mapVersion(array $version, MarketplaceProjectType $type): MarketplaceVersion
    {
        $extension = $type === MarketplaceProjectType::Modpack ? '.mrpack' : '.jar';
        $files = Payload::objects($version, 'files');
        usort($files, fn (array $a, array $b): int => (int) Payload::bool($b, 'primary') <=> (int) Payload::bool($a, 'primary'));

        $file = null;
        foreach ($files as $candidate) {
            $filename = Payload::string($candidate, 'filename');
            if (str_ends_with(mb_strtolower($filename), $extension)) {
                $hashes = [];
                foreach (['sha1', 'sha512'] as $algorithm) {
                    $value = mb_strtolower(Payload::string(Payload::array($candidate, 'hashes'), $algorithm));
                    if (preg_match('/^[0-9a-f]+$/', $value) === 1) {
                        $hashes[$algorithm] = $value;
                    }
                }

                $file = new MarketplaceFile(Payload::string($candidate, 'url'), $filename, Payload::int($candidate, 'size'), $hashes);
                break;
            }
        }

        return new MarketplaceVersion(
            id: Payload::string($version, 'id'),
            projectId: Payload::string($version, 'project_id'),
            name: Payload::string($version, 'name', Payload::string($version, 'version_number')),
            versionNumber: Payload::string($version, 'version_number'),
            gameVersions: Payload::strings($version, 'game_versions'),
            loaders: Payload::strings($version, 'loaders'),
            channel: Payload::string($version, 'version_type', 'release'),
            publishedAt: Payload::nullableString($version, 'date_published'),
            file: $file,
        );
    }

    private function httpsUrl(string $url): ?string
    {
        return str_starts_with($url, 'https://') ? $url : null;
    }
}
