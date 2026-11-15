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
 * PaperMC's Hangar (https://hangar.papermc.io/api-docs): plugins for Paper, Velocity and
 * Waterfall. Public reads need no key. Files hosted elsewhere (an "external URL") cannot be
 * installed, since only Hangar's CDN is allowed.
 */
final readonly class HangarCatalog implements MarketplaceCatalog
{
    public const string BASE_URL = 'https://hangar.papermc.io/api/v1';

    private const array PLATFORMS = ['PAPER', 'VELOCITY', 'WATERFALL'];

    public function __construct(private MarketplaceHttp $http) {}

    public function source(): MarketplaceSource
    {
        return MarketplaceSource::Hangar;
    }

    public function available(): bool
    {
        return true;
    }

    public function supports(MarketplaceProjectType $type, ServerPlatform $platform): bool
    {
        return $type === MarketplaceProjectType::Plugin && ($platform->loader === null || $platform->hangarPlatform() !== null);
    }

    public function search(MarketplaceSearch $search): MarketplaceSearchResult
    {
        return $this->http->remember($this->source(), 'search', $search->cacheKey(), function () use ($search): MarketplaceSearchResult {
            $query = array_filter([
                'query' => mb_trim($search->query),
                'platform' => $search->platform->hangarPlatform(),
                // Velocity and Waterfall versions are proxy versions, not Minecraft ones.
                'version' => $search->platform->hangarPlatform() === 'PAPER' ? $search->platform->gameVersion : null,
                'sort' => match ($search->sort) {
                    'downloads' => '-downloads',
                    'updated' => '-updated',
                    'newest' => '-newest',
                    default => null,
                },
                'limit' => MarketplaceSearch::PER_PAGE,
                'offset' => $search->offset(),
            ], fn (int|string|null $value): bool => $value !== null && $value !== '');

            $data = $this->http->json($this->source(), 'GET', self::BASE_URL.'/projects', $query) ?? [];

            $projects = [];
            foreach (Payload::objects($data, 'result') as $project) {
                $namespace = Payload::array($project, 'namespace');
                $slug = Payload::string($namespace, 'slug');
                if ($slug === '') {
                    continue;
                }

                $owner = Payload::string($namespace, 'owner');
                $projects[] = new MarketplaceProject(
                    source: $this->source(),
                    id: $slug,
                    slug: $slug,
                    title: Payload::string($project, 'name', $slug),
                    description: Payload::string($project, 'description'),
                    author: $owner === '' ? null : $owner,
                    iconUrl: str_starts_with(Payload::string($project, 'avatarUrl'), 'https://') ? Payload::string($project, 'avatarUrl') : null,
                    downloads: Payload::int(Payload::array($project, 'stats'), 'downloads'),
                    updatedAt: Payload::nullableString($project, 'lastUpdated'),
                    url: 'https://hangar.papermc.io/'.rawurlencode($owner).'/'.rawurlencode($slug),
                );
            }

            return new MarketplaceSearchResult($projects, Payload::int(Payload::array($data, 'pagination'), 'count'), $search->page, MarketplaceSearch::PER_PAGE);
        });
    }

    public function versions(string $project, MarketplaceProjectType $type, ServerPlatform $platform): array
    {
        $platformName = $platform->hangarPlatform() ?? 'PAPER';
        $query = array_filter([
            'platform' => $platformName,
            'platformVersion' => $platformName === 'PAPER' ? $platform->gameVersion : null,
            'limit' => 25,
            'offset' => 0,
        ], fn (int|string|null $value): bool => $value !== null);

        return $this->http->remember($this->source(), 'versions', [$project, $query], function () use ($project, $query, $platformName): array {
            $data = $this->http->json($this->source(), 'GET', self::BASE_URL.'/projects/'.rawurlencode($project).'/versions', $query, nullOn404: true) ?? [];

            return array_map(fn (array $version): MarketplaceVersion => $this->mapVersion($version, $project, $platformName), Payload::objects($data, 'result'));
        });
    }

    public function version(string $project, string $version, MarketplaceProjectType $type): ?MarketplaceVersion
    {
        $data = $this->http->json($this->source(), 'GET', self::BASE_URL.'/projects/'.rawurlencode($project).'/versions/'.rawurlencode($version), nullOn404: true);
        if ($data === null) {
            return null;
        }

        // The platform the version was asked for is not known here: take the first with a file.
        $downloads = Payload::array($data, 'downloads');
        foreach (self::PLATFORMS as $platform) {
            if (is_array($downloads[$platform] ?? null)) {
                return $this->mapVersion($data, $project, $platform);
            }
        }

        return $this->mapVersion($data, $project, 'PAPER');
    }

    /** @param  array<mixed>  $version */
    private function mapVersion(array $version, string $project, string $platform): MarketplaceVersion
    {
        $download = Payload::array(Payload::array($version, 'downloads'), $platform);
        $info = Payload::array($download, 'fileInfo');
        $url = Payload::string($download, 'downloadUrl');
        $hash = mb_strtolower(Payload::string($info, 'sha256Hash'));

        $file = $url === '' ? null : new MarketplaceFile(
            $url,
            Payload::string($info, 'name'),
            Payload::int($info, 'sizeBytes'),
            preg_match('/^[0-9a-f]{64}$/', $hash) === 1 ? ['sha256' => $hash] : [],
        );

        $name = Payload::string($version, 'name');

        return new MarketplaceVersion(
            id: Payload::string($version, 'id', $name),
            projectId: $project,
            name: $name,
            versionNumber: $name,
            gameVersions: Payload::strings(Payload::array($version, 'platformDependencies'), $platform),
            loaders: [mb_strtolower($platform)],
            channel: mb_strtolower(Payload::string(Payload::array($version, 'channel'), 'name', 'release')),
            publishedAt: Payload::nullableString($version, 'createdAt'),
            file: $file,
        );
    }
}
