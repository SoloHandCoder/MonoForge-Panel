<?php

declare(strict_types=1);

namespace Pterodactyl\Contracts\Files;

use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;

interface ListsDirectories
{
    /**
     * @param  int|null  $timeout  seconds to wait for Wings, instead of the panel's default
     * @return list<DaemonFileObject>
     *
     * @throws DaemonConnectionException
     */
    public function list(Server $server, string $directory = '/', ?int $timeout = null): array;
}
