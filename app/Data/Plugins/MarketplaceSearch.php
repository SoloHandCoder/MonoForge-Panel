<?php

declare(strict_types=1);

namespace Pterodactyl\Data\Plugins;

use Pterodactyl\Enum\MarketplaceProjectType;
use Pterodactyl\Services\Plugins\ServerPlatform;

/** What to search a catalog for. */
final readonly class MarketplaceSearch
{
    public const int PER_PAGE = 20;

    public function __construct(
        public string $query,
        public MarketplaceProjectType $type,
        public ServerPlatform $platform,
        public int $page = 1,
        public string $sort = 'relevance',
    ) {}

    public function offset(): int
    {
        return ($this->page - 1) * self::PER_PAGE;
    }

    /** @return array<string, int|string|null> */
    public function cacheKey(): array
    {
        return [
            'q' => mb_strtolower(mb_trim($this->query)),
            't' => $this->type->value,
            'l' => $this->platform->loader,
            'v' => $this->platform->gameVersion,
            'p' => $this->page,
            's' => $this->sort,
        ];
    }
}
