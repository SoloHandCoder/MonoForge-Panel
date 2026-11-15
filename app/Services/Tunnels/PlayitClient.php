<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Tunnels;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Exceptions\Tunnels\PlayitException;

/**
 * The playit.gg API as the official playit agent uses it. playit does not document it, so this
 * follows the agent's own client (packages/api_client): every call is a JSON POST to
 * `https://api.playit.gg{path}` with `Authorization: Agent-Key <secret>`, answered with
 * `{"status": "success"|"fail"|"error", "data": ...}`. A "fail" carries the endpoint's own error
 * code (such as "AgentNotFound") and an "error" carries `{"type", "message"}`.
 *
 * The agent secret is only ever sent in the header; it is never logged or put in a message.
 */
class PlayitClient
{
    public const string API_BASE = 'https://api.playit.gg';

    /**
     * The address players connect to for an allocated tunnel, or null while it is not allocated.
     * Minecraft Java tunnels get an SRV name that needs no port; everything else is host:port.
     *
     * @param  array<mixed>  $tunnel
     */
    public static function addressOf(array $tunnel): ?string
    {
        $alloc = $tunnel['alloc'] ?? null;
        if (! is_array($alloc) || ($alloc['status'] ?? null) !== 'allocated' || ! is_array($alloc['data'] ?? null)) {
            return null;
        }

        $data = $alloc['data'];
        $srv = $data['assigned_srv'] ?? null;
        if (($tunnel['tunnel_type'] ?? null) === 'minecraft-java' && is_string($srv) && $srv !== '') {
            return $srv;
        }

        $host = $data['assigned_domain'] ?? null;
        if (! is_string($host) || $host === '') {
            $host = $data['ip_hostname'] ?? null;
        }

        if (! is_string($host) || $host === '') {
            return null;
        }

        $port = $data['port_start'] ?? null;

        return is_int($port) ? $host.':'.$port : $host;
    }

    /**
     * Create a tunnel to a port on the agent's machine and return playit's id for it.
     *
     * @param  array{name: string|null, tunnel_type: string|null, port_type: string, local_ip: string, local_port: int}  $tunnel
     *
     * @throws PlayitException
     */
    public function createTunnel(string $secret, string $agentId, array $tunnel): string
    {
        $data = $this->call($secret, '/tunnels/create', [
            'name' => $tunnel['name'],
            'tunnel_type' => $tunnel['tunnel_type'],
            'port_type' => $tunnel['port_type'],
            'port_count' => 1,
            'origin' => [
                'type' => 'agent',
                'data' => [
                    'agent_id' => $agentId,
                    'local_ip' => $tunnel['local_ip'],
                    'local_port' => $tunnel['local_port'],
                ],
            ],
            'enabled' => true,
            'alloc' => null,
            'firewall_id' => null,
            'proxy_protocol' => null,
        ]);

        $id = is_array($data) ? ($data['id'] ?? null) : null;
        throw_unless(is_string($id) && $id !== '', PlayitException::class, 'playit.gg did not return an id for the new tunnel.');

        return $id;
    }

    /**
     * The tunnels the secret can see, optionally only those of one agent.
     *
     * @return list<array<mixed>>
     *
     * @throws PlayitException
     */
    public function listTunnels(string $secret, ?string $agentId = null, ?string $tunnelId = null): array
    {
        $data = $this->call($secret, '/tunnels/list', ['tunnel_id' => $tunnelId, 'agent_id' => $agentId]);
        $tunnels = is_array($data) ? ($data['tunnels'] ?? null) : null;
        throw_unless(is_array($tunnels), PlayitException::class, 'playit.gg returned an unexpected tunnel list.');

        return array_values(array_filter($tunnels, is_array(...)));
    }

    /**
     * One tunnel, or null when playit no longer has it.
     *
     * @return array<mixed>|null
     *
     * @throws PlayitException
     */
    public function findTunnel(string $secret, string $tunnelId): ?array
    {
        foreach ($this->listTunnels($secret, null, $tunnelId) as $tunnel) {
            if (($tunnel['id'] ?? null) === $tunnelId) {
                return $tunnel;
            }
        }

        return null;
    }

    /**
     * Delete a tunnel. A tunnel playit no longer has counts as deleted.
     *
     * @throws PlayitException
     */
    public function deleteTunnel(string $secret, string $tunnelId): void
    {
        try {
            $this->call($secret, '/tunnels/delete', ['tunnel_id' => $tunnelId]);
        } catch (PlayitException $playitException) {
            throw_if($playitException->reason !== 'TunnelNotFound', $playitException);
        }
    }

    /**
     * POST to the API and unwrap its envelope.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws PlayitException
     */
    protected function call(string $secret, string $path, array $body): mixed
    {
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting()
                ->withHeaders(['Authorization' => 'Agent-Key '.mb_trim($secret)])
                ->post(self::API_BASE.$path, $body);
        } catch (ConnectionException) {
            throw new PlayitException('Could not reach playit.gg.');
        }

        return $this->unwrap($response);
    }

    private function describe(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? mb_substr($encoded, 0, 200) : 'unknown';
    }

    /** "auth: InvalidAgentKey", "validation: <text>", "path-not-found: /x" or "internal". */
    private function describeError(mixed $error): string
    {
        if (! is_array($error)) {
            return $this->describe($error);
        }

        $type = is_string($error['type'] ?? null) ? $error['type'] : 'unknown';
        $message = $error['message'] ?? null;

        return match (true) {
            $type === 'internal' => 'internal',
            is_string($message) => $type.': '.mb_substr($message, 0, 200),
            is_array($message) && is_string($message['path'] ?? null) => $type.': '.$message['path'],
            default => $type,
        };
    }

    private function errorReason(mixed $error): ?string
    {
        if (! is_array($error)) {
            return null;
        }

        $message = $error['message'] ?? null;
        if (($error['type'] ?? null) === 'auth' && is_string($message)) {
            return $message;
        }

        return is_string($error['type'] ?? null) ? $error['type'] : null;
    }

    /**
     * @throws PlayitException
     */
    private function unwrap(Response $response): mixed
    {
        throw_if($response->status() === 429, PlayitException::class, 'playit.gg is rate limiting the panel; it will try again later.', 'TooManyRequests');

        $json = $response->json();
        if (! is_array($json) || ! is_string($json['status'] ?? null)) {
            throw new PlayitException(sprintf('playit.gg answered with HTTP %d and no readable body.', $response->status()));
        }

        $data = $json['data'] ?? null;

        return match ($json['status']) {
            'success' => $data,
            'fail' => throw new PlayitException('playit.gg refused the request: '.$this->describe($data).'.', is_string($data) ? $data : null),
            'error' => throw new PlayitException('playit.gg returned an error: '.$this->describeError($data).'.', $this->errorReason($data)),
            default => throw new PlayitException(sprintf('playit.gg answered with an unknown status (HTTP %d).', $response->status())),
        };
    }
}
