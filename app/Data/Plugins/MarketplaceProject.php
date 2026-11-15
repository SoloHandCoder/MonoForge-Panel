<?php

declare(strict_types=1);

namespace Pterodactyl\Data\Plugins;

use Pterodactyl\Enum\MarketplaceSource;

/** A search result from a catalog. */
final readonly class MarketplaceProject
{
    public function __construct(
        public MarketplaceSource $source,
        public string $id,
        public string $slug,
        public string $title,
        public string $description,
        public ?string $author,
        public ?string $iconUrl,
        public int $downloads,
        public ?string $updatedAt,
        public string $url,
        public bool $clientOnly = false,
    ) {}

    /**
     * @return array{source: string, id: string, slug: string, title: string, description: string, author: string|null, icon_url: string|null, downloads: int, updated_at: string|null, url: string, client_only: bool}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source->value,
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'author' => $this->author,
            'icon_url' => $this->iconUrl,
            'downloads' => $this->downloads,
            'updated_at' => $this->updatedAt,
            'url' => $this->url,
            'client_only' => $this->clientOnly,
        ];
    }
}
