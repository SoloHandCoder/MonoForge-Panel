<?php

declare(strict_types=1);

namespace Pterodactyl\Contracts\Servers;

use Pterodactyl\Data\ServerState;
use Pterodactyl\Data\ServerStateUnavailable;
use Pterodactyl\Models\Server;

interface ReadsServerStates
{
    /**
     * Read the live state of many servers at once. Readings share the single-server
     * cache; a server whose node does not answer gets an unavailable entry instead of
     * failing the others.
     *
     * @param  iterable<Server>  $servers  servers with their node loaded
     * @return array<string, ServerState|ServerStateUnavailable> keyed by server uuid
     */
    public function read(iterable $servers): array;
}
