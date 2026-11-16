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

    /** playit's error when the agent key may read but not create or delete tunnels. */
    public const string READ_ONLY = 'NotAllowedWithReadOnly';

    /** playit's error on create while no agent program has connected with the key yet. */
    public const string AGENT_VERSION_TOO_OLD = 'AgentVersionTooOld';

    /** playit's answer when the account has no room for another tunnel. */
    public const string TUNNEL_LIMIT = 'AccountUnassignedTunnelLimitReached';

    /** Where the administrator accepts a claim: the official agent's `claim_url()`. */
    public const string CLAIM_URL = 'https://playit.gg/claim/';

    /** The agent version the panel claims as, which playit accepts for self-managed agents. */
    public const string CLAIM_VERSION = 'playit 2.0.2';

    /** `/claim/setup` answers, as the official agent's ClaimSetupResponse names them. */
    public const string CLAIM_WAITING_FOR_VISIT = 'WaitingForUserVisit';

    public const string CLAIM_WAITING_FOR_USER = 'WaitingForUser';

    public const string CLAIM_ACCEPTED = 'UserAccepted';

    public const string CLAIM_REJECTED = 'UserRejected';

    /** What playit's agent secrets look like: the agent checks they are hex. */
    public const string SECRET_PATTERN = '/^[0-9a-fA-F]{16,512}$/';

    public const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * The address players connect to, or null while playit has not allocated one or has disabled
     * the tunnel. The v1 agent run data carries it as `display_address`; the older tunnel list
     * only has the allocation, where Minecraft Java tunnels get an SRV name that needs no port
     * and everything else is host:port.
     *
     * @param  array<mixed>  $tunnel
     */
    public static function addressOf(array $tunnel): ?string
    {
        if (self::disabledReason($tunnel) !== null) {
            return null;
        }

        $display = $tunnel['display_address'] ?? null;
        if (is_string($display) && mb_trim($display) !== '') {
            return mb_trim($display);
        }

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
     * Why playit disabled the tunnel, or null when it is not disabled. Run data has
     * `disabled_reason`; the tunnel list has `alloc: {status: "disabled", data: {reason}}`.
     *
     * @param  array<mixed>  $tunnel
     */
    public static function disabledReason(array $tunnel): ?string
    {
        $reason = $tunnel['disabled_reason'] ?? null;
        if (is_string($reason) && $reason !== '') {
            return $reason;
        }

        if (is_array($reason)) {
            return self::describeValue($reason);
        }

        $alloc = $tunnel['alloc'] ?? null;
        if (is_array($alloc) && ($alloc['status'] ?? null) === 'disabled') {
            return is_array($alloc['data'] ?? null) && is_string($alloc['data']['reason'] ?? null) ? $alloc['data']['reason'] : 'unknown';
        }

        return null;
    }

    /**
     * The port on the agent's machine the tunnel forwards to: `agent_config.fields` in run data
     * (a string there), `origin.data.local_port` in the tunnel list.
     *
     * @param  array<mixed>  $tunnel
     */
    public static function localPort(array $tunnel): ?int
    {
        $port = self::configField($tunnel, 'local_port') ?? self::originData($tunnel)['local_port'] ?? null;
        if (is_string($port) && ctype_digit(mb_trim($port))) {
            $port = (int) mb_trim($port);
        }

        return is_int($port) && $port > 0 && $port <= 65535 ? $port : null;
    }

    /**
     * The address on the agent's machine the tunnel forwards to, lower-cased, when it has one.
     *
     * @param  array<mixed>  $tunnel
     */
    public static function localIp(array $tunnel): ?string
    {
        $ip = self::configField($tunnel, 'local_ip') ?? self::originData($tunnel)['local_ip'] ?? null;

        return is_string($ip) && mb_trim($ip) !== '' ? mb_strtolower(mb_trim($ip)) : null;
    }

    /**
     * Whether the tunnel points at a local port of an agent (rather than, say, a managed one).
     *
     * @param  array<mixed>  $tunnel
     */
    public static function isAgentTunnel(array $tunnel): bool
    {
        if (is_array($tunnel['agent_config'] ?? null)) {
            return true;
        }

        $origin = $tunnel['origin'] ?? null;

        return is_array($origin) && ($origin['type'] ?? null) === 'agent';
    }

    /**
     * The agent the tunnel belongs to, lower-cased, when playit says.
     *
     * @param  array<mixed>  $tunnel
     */
    public static function agentIdOf(array $tunnel): ?string
    {
        $agentId = $tunnel['agent_id'] ?? self::originData($tunnel)['agent_id'] ?? null;

        return is_string($agentId) && $agentId !== '' ? mb_strtolower($agentId) : null;
    }

    /**
     * The `/tunnels/create` body (the agent api_client's `ReqTunnelsCreate`) for a tunnel to
     * plain ports, exactly as playit parses it.
     *
     * @param  array{name: string|null, tunnel_type: string|null, port_type: string, local_ip: string, local_port: int}  $tunnel
     * @return array<string, mixed>
     */
    public static function rawTunnelBody(string $agentId, array $tunnel): array
    {
        return [
            'name' => $tunnel['name'],
            'tunnel_type' => null,
            'port_type' => in_array($tunnel['port_type'], ['tcp', 'udp', 'both'], true) ? $tunnel['port_type'] : 'both',
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
        ];
    }

    /**
     * Register (or poll) a claim for a new self-managed agent, as `playit claim exchange` does:
     * `POST /claim/setup {code, agent_type: "self-managed", version}` with no agent key. Returns
     * one of the CLAIM_* answers.
     *
     * @throws PlayitException
     */
    public function claimSetup(string $code): string
    {
        $data = $this->call(null, '/claim/setup', [
            'code' => $code,
            'agent_type' => 'self-managed',
            'version' => self::CLAIM_VERSION,
        ]);

        $known = [self::CLAIM_WAITING_FOR_VISIT, self::CLAIM_WAITING_FOR_USER, self::CLAIM_ACCEPTED, self::CLAIM_REJECTED];
        throw_if(! is_string($data) || ! in_array($data, $known, true), PlayitException::class, 'playit.gg answered the claim with something the panel does not know.');

        return $data;
    }

    /**
     * Exchange an accepted claim for the new agent's secret (`POST /claim/exchange {code}`). Until
     * the claim is accepted playit fails it with `NotAccepted`; a refused or expired claim fails
     * with `UserRejected`, `CodeExpired` or `CodeNotFound`.
     *
     * The secret is returned to the caller only; it is never logged or put in a message.
     *
     * @throws PlayitException
     */
    public function claimExchange(string $code): string
    {
        $data = $this->call(null, '/claim/exchange', ['code' => $code]);
        $secret = is_array($data) ? ($data['secret_key'] ?? null) : null;

        throw_if(! is_string($secret) || preg_match(self::SECRET_PATTERN, mb_trim($secret)) !== 1, PlayitException::class, 'playit.gg did not return a usable agent secret.');

        return mb_trim($secret);
    }

    /**
     * The agent behind a secret, from its run data: its id and whether it is self-managed.
     *
     * @return array{agent_id: string, self_managed: bool|null}
     *
     * @throws PlayitException
     */
    public function agentOf(string $secret): array
    {
        $data = $this->call($secret, '/v1/agents/rundata', []);
        $agentId = is_array($data) ? ($data['agent_id'] ?? null) : null;

        throw_if(! is_string($agentId) || preg_match(self::UUID_PATTERN, $agentId) !== 1, PlayitException::class, 'playit.gg did not say which agent the new key belongs to.');

        $permissions = $data['permissions'] ?? null;
        $selfManaged = is_array($permissions) && is_bool($permissions['is_self_managed'] ?? null) ? $permissions['is_self_managed'] : null;

        return ['agent_id' => mb_strtolower($agentId), 'self_managed' => $selfManaged];
    }

    /**
     * Create a tunnel to a port on the agent's machine and return playit's id for it. Only a
     * write-capable agent key may do this; a read-only one is refused with
     * `NotAllowedWithReadOnly` (see READ_ONLY).
     *
     * A tunnel for a game playit knows (`tunnel_type`) uses the v1 API, which works. A tunnel
     * for plain TCP/UDP ports uses the `/tunnels/create` body of the official agent's
     * api_client (`ReqTunnelsCreate`: flat `tunnel_type`, `port_type` and `port_count`, and an
     * `agent` origin carrying `local_ip` and a numeric `local_port`), because playit refuses
     * the v1 raw-port bodies with "validation: failed to parse body". The v1 shape
     * (`ReqTunnelsCreateV1`, `{"type": "custom-udp", "details": 1}`) stays as the fallback.
     *
     * @param  array{name: string|null, tunnel_type: string|null, port_type: string, local_ip: string, local_port: int}  $tunnel
     *
     * @throws PlayitException
     */
    public function createTunnel(string $secret, string $agentId, array $tunnel): string
    {
        $data = $tunnel['tunnel_type'] !== null
            ? $this->createTypedTunnel($secret, $agentId, $tunnel)
            : $this->createRawTunnel($secret, $agentId, $tunnel);

        $id = is_array($data) ? ($data['id'] ?? null) : null;
        throw_unless(is_string($id) && $id !== '', PlayitException::class, 'playit.gg did not return an id for the new tunnel.');

        return $id;
    }

    /**
     * The agent's tunnels as its own run data lists them (`POST /v1/agents/rundata`), which every
     * agent key may read and which carries `display_address`. Falls back to the tunnel list when
     * playit does not answer the run data call.
     *
     * @return list<array<mixed>>
     *
     * @throws PlayitException
     */
    public function agentTunnels(string $secret, string $agentId): array
    {
        try {
            $data = $this->call($secret, '/v1/agents/rundata', []);
            $tunnels = is_array($data) ? ($data['tunnels'] ?? null) : null;
            if (is_array($tunnels)) {
                return array_values(array_filter($tunnels, is_array(...)));
            }
        } catch (PlayitException $playitException) {
            // A key playit does not know fails the list call the same way; say so right away.
            throw_if($playitException->reason === 'InvalidAgentKey', $playitException);
        }

        return $this->listTunnels($secret, $agentId);
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
    public function findTunnel(string $secret, string $tunnelId, ?string $agentId = null): ?array
    {
        $tunnels = $agentId !== null && $agentId !== '' ? $this->agentTunnels($secret, $agentId) : $this->listTunnels($secret, null, $tunnelId);

        foreach ($tunnels as $tunnel) {
            if (($tunnel['id'] ?? null) === $tunnelId) {
                return $tunnel;
            }
        }

        return null;
    }

    /**
     * Delete a tunnel (`POST /tunnels/delete`). A tunnel playit no longer has counts as deleted.
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
     * POST to the API and unwrap its envelope, with the agent key when there is one.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws PlayitException
     */
    protected function call(?string $secret, string $path, array $body): mixed
    {
        $request = Http::acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->withoutRedirecting();

        // The claim calls are made before there is a key, like the official agent's.
        if ($secret !== null) {
            $request = $request->withHeaders(['Authorization' => 'Agent-Key '.mb_trim($secret)]);
        }

        try {
            $response = $request->post(self::API_BASE.$path, $body);
        } catch (ConnectionException) {
            throw new PlayitException('Could not reach playit.gg.');
        }

        return $this->unwrap($response);
    }

    private static function describeValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? mb_substr($encoded, 0, 200) : 'unknown';
    }

    /**
     * @param  array<mixed>  $tunnel
     */
    private static function configField(array $tunnel, string $name): mixed
    {
        $config = $tunnel['agent_config'] ?? null;
        $fields = is_array($config) ? ($config['fields'] ?? null) : null;
        if (! is_array($fields)) {
            return null;
        }

        foreach ($fields as $field) {
            if (is_array($field) && ($field['name'] ?? null) === $name) {
                return $field['value'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $tunnel
     * @return array<mixed>
     */
    private static function originData(array $tunnel): array
    {
        $origin = $tunnel['origin'] ?? null;

        return is_array($origin) && is_array($origin['data'] ?? null) ? $origin['data'] : [];
    }

    /**
     * @param  array{name: string|null, tunnel_type: string|null, port_type: string, local_ip: string, local_port: int}  $tunnel
     *
     * @throws PlayitException
     */
    private function createRawTunnel(string $secret, string $agentId, array $tunnel): mixed
    {
        try {
            return $this->call($secret, '/tunnels/create', self::rawTunnelBody($agentId, $tunnel));
        } catch (PlayitException $playitException) {
            throw_if($playitException->reason !== 'validation', $playitException);

            return $this->call($secret, '/v1/tunnels/create', [
                'ports' => ['type' => match ($tunnel['port_type']) {
                    'udp' => 'custom-udp',
                    'tcp' => 'custom-tcp',
                    default => 'custom-both',
                }, 'details' => 1],
                'origin' => $this->v1Origin($agentId, $tunnel),
                'enabled' => true,
                'alloc' => null,
                'name' => $tunnel['name'],
                'firewall_id' => null,
            ]);
        }
    }

    /**
     * @param  array{name: string|null, tunnel_type: string|null, port_type: string, local_ip: string, local_port: int}  $tunnel
     *
     * @throws PlayitException
     */
    private function createTypedTunnel(string $secret, string $agentId, array $tunnel): mixed
    {
        $origin = $this->v1Origin($agentId, $tunnel);

        try {
            return $this->call($secret, '/v1/tunnels/create', [
                'name' => $tunnel['name'],
                'protocol' => ['type' => 'tunnel-type', 'details' => $tunnel['tunnel_type']],
                'origin' => $origin,
                'endpoint' => ['type' => 'region', 'details' => ['region' => 'global', 'port' => null]],
                'enabled' => true,
            ]);
        } catch (PlayitException $playitException) {
            // When playit refuses this one as malformed, send the official agent's
            // ReqTunnelsCreateV1 shape instead.
            throw_if($playitException->reason !== 'validation', $playitException);

            return $this->call($secret, '/v1/tunnels/create', [
                'ports' => ['type' => 'tunnel-type', 'details' => $tunnel['tunnel_type']],
                'origin' => $origin,
                'enabled' => true,
                'alloc' => null,
                'name' => $tunnel['name'],
                'firewall_id' => null,
            ]);
        }
    }

    /**
     * @param  array{name: string|null, tunnel_type: string|null, port_type: string, local_ip: string, local_port: int}  $tunnel
     * @return array<string, mixed>
     */
    private function v1Origin(string $agentId, array $tunnel): array
    {
        return [
            'type' => 'agent',
            'data' => [
                'agent_id' => $agentId,
                'config' => [
                    'fields' => [
                        ['name' => 'local_ip', 'value' => $tunnel['local_ip']],
                        ['name' => 'local_port', 'value' => (string) $tunnel['local_port']],
                    ],
                ],
            ],
        ];
    }

    /** "auth: InvalidAgentKey", "validation: <text>", "path-not-found: /x" or "internal". */
    private function describeError(mixed $error): string
    {
        if (! is_array($error)) {
            return self::describeValue($error);
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
            'fail' => throw new PlayitException('playit.gg refused the request: '.self::describeValue($data).'.', is_string($data) ? $data : null),
            'error' => throw new PlayitException('playit.gg returned an error: '.$this->describeError($data).'.', $this->errorReason($data)),
            default => throw new PlayitException(sprintf('playit.gg answered with an unknown status (HTTP %d).', $response->status())),
        };
    }
}
