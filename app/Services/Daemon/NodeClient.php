<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Daemon;

use Illuminate\Http\Client\Response;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

final readonly class NodeClient
{
    private function __construct(private DaemonConnection $connection) {}

    public static function forNode(Node $node): self
    {
        return new self(DaemonConnection::forNode($node));
    }

    /** A client for one of this node's servers that reuses this node's connection details. */
    public function server(Server $server): ServerClient
    {
        return ServerClient::onConnection($server->uuid, $this->connection);
    }

    /** @return array<mixed> */
    public function systemInformation(?int $version = null): array
    {
        $response = $this->connection->request('GET', '/api/system', [
            'query' => $version === null ? [] : ['v' => $version],
        ]);

        return $this->connection->decode($response);
    }

    public function update(Node $node): Response
    {
        return $this->connection->request('POST', '/api/update', ['json' => $node->getConfiguration()]);
    }

    /** @param list<string> $servers */
    public function deauthorize(string $user, array $servers = []): void
    {
        $this->connection->request('POST', '/api/deauthorize-user', ['json' => ['user' => $user, 'servers' => $servers]]);
    }
}
