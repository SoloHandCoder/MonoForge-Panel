<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Chat;

/**
 * The tokens one model call used, normalised across providers.
 */
final readonly class ChatUsage
{
    public function __construct(
        public int $input = 0,
        public int $output = 0,
        public int $cached = 0,
        public ?float $cost = null,
        /** Whether the provider reported the numbers (false: they were estimated). */
        public bool $reported = true,
    ) {}

    public function total(): int
    {
        return $this->input + $this->output;
    }
}
