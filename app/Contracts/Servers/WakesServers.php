<?php

declare(strict_types=1);

namespace Pterodactyl\Contracts\Servers;

use Pterodactyl\Models\Server;

/**
 * Pay-as-you-play: servers that auto-sleep while nobody plays, and waking them up again.
 */
interface WakesServers
{
    /**
     * Starts a sleeping server. True when it was started (or is being started by another
     * request), false when it was not sleeping or could not be started.
     */
    public function wake(Server $server): bool;

    /** Whether auto-sleep stopped the server and nobody has started it since. */
    public function isSleeping(Server $server): bool;
}
