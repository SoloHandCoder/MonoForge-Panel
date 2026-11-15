<?php

declare(strict_types=1);

namespace Pterodactyl\Data\Plugins;

/** One page of catalog search results. */
final readonly class MarketplaceSearchResult
{
    /** @param  list<MarketplaceProject>  $projects */
    public function __construct(
        public array $projects,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
