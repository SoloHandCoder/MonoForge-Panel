<?php

declare(strict_types=1);

namespace Pterodactyl\Data\Plugins;

/** A version of a catalog project and the file that would be installed for it. */
final readonly class MarketplaceVersion
{
    /**
     * @param  list<string>  $gameVersions
     * @param  list<string>  $loaders
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $name,
        public string $versionNumber,
        public array $gameVersions,
        public array $loaders,
        public string $channel,
        public ?string $publishedAt,
        public ?MarketplaceFile $file,
    ) {}

    /**
     * @return array{id: string, project_id: string, name: string, version_number: string, game_versions: list<string>, loaders: list<string>, channel: string, published_at: string|null, file: array{filename: string, size: int, hashes: array<string, string>}|null, installable: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->projectId,
            'name' => $this->name,
            'version_number' => $this->versionNumber,
            'game_versions' => $this->gameVersions,
            'loaders' => $this->loaders,
            'channel' => $this->channel,
            'published_at' => $this->publishedAt,
            'file' => $this->file?->toArray(),
            'installable' => $this->file instanceof MarketplaceFile,
        ];
    }
}
