<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Query;

/**
 * Opens query connections. Bound to StreamQueryConnector; tests bind a fake that plays a game
 * server's side of the conversation.
 */
interface QueryConnector
{
    /** A connection, or null when the server does not accept one within the timeout. */
    public function open(string $host, int $port, float $timeoutSeconds): ?QueryConnection;
}
