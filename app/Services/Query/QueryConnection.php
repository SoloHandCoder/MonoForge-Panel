<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Query;

/**
 * An open TCP connection to a game server, as the query protocols use it.
 */
interface QueryConnection
{
    /** Sends every byte; false when the connection failed. */
    public function write(string $bytes): bool;

    /** Reads exactly $length bytes, or null on a timeout or a closed connection. */
    public function read(int $length): ?string;

    public function close(): void;
}
