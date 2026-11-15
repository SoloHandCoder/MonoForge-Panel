<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Query;

/**
 * What a game server says about itself when asked: who is online and what it runs.
 */
final readonly class QueryStatus
{
    /**
     * @param  list<string>  $players  the names the game shares (may be empty even with players online)
     */
    public function __construct(
        public int $online,
        public int $max,
        public ?string $motd,
        public ?string $version,
        public array $players,
    ) {}
}
