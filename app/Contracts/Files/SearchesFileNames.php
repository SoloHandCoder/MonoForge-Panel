<?php

declare(strict_types=1);

namespace Pterodactyl\Contracts\Files;

use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;

interface SearchesFileNames
{
    /**
     * Find the files below a directory whose path contains every word of the query.
     *
     * @return array{matches: list<array{path: string, name: string, is_file: bool, size: int}>, truncated: bool}
     *
     * @throws DaemonConnectionException
     */
    public function search(Server $server, string $query, string $directory = '/'): array;
}
