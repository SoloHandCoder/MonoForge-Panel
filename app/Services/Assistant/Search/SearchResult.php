<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Search;

/**
 * What a web search found: a short answer when the search service writes one, and the sources
 * (public http(s) links only), at most WebSearch::MAX_RESULTS of them.
 */
final readonly class SearchResult
{
    /**
     * @param  list<array{title: string, url: string, snippet: string}>  $sources
     */
    public function __construct(
        public ?string $summary,
        public array $sources,
    ) {}
}
