<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Gateway;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

/**
 * The game gateway card of a node in the admin area: its settings, the gateway.sh commands
 * filled with its ports, and the "Check" that lists which of the node's allocations the
 * gateway forwards. Nothing here touches the network.
 *
 * @phpstan-type GatewayCommands array{download: string, vps_init: string, node_init: string, vps_add_node: string, status: string}
 * @phpstan-type GatewaySettings array{enabled: bool, active: bool, host: string|null, ports: string, ranges: list<array{int, int}>, script: array{url: string, sha256: string|null}, commands: GatewayCommands}
 * @phpstan-type GatewayCheckServer array{server_id: int, server_name: string, port: int, primary: bool}
 * @phpstan-type GatewayCheck array{ranges: list<array{int, int}>, forwarded: array{total: int, assigned: int, ports: string}, not_forwarded: array{total: int, assigned: int, ports: string}, servers_not_forwarded: list<GatewayCheckServer>}
 */
class GameGatewayService
{
    /** Where the panel serves gateway.sh (routes/base.php). */
    public const string SCRIPT_PATH = '/scripts/gateway.sh';

    /** The most servers the check lists by name. */
    public const int MAX_LISTED_SERVERS = 100;

    public static function scriptFile(): string
    {
        return base_path('scripts/monoforge/gateway.sh');
    }

    /**
     * The commands from the top of gateway.sh, in the order they run. The VPS's IP and the
     * public keys are printed by the script itself; they are placeholders here.
     *
     * @param  array{url: string, sha256: string|null}  $script
     * @return GatewayCommands
     */
    public static function commands(array $script, string $ports): array
    {
        $download = 'curl -fsSL -o gateway.sh '.$script['url'];
        if ($script['sha256'] !== null) {
            $download .= "\n".sprintf('echo "%s  gateway.sh" | sha256sum -c -', $script['sha256']);
        }

        return [
            'download' => $download,
            'vps_init' => 'sudo bash gateway.sh vps-init --ports '.GatewayPorts::normalize($ports),
            'node_init' => 'sudo bash gateway.sh node-init --gateway <VPS public IP> --gateway-key <VPS public key>',
            'vps_add_node' => 'sudo bash gateway.sh vps-add-node --node-key <node public key>',
            'status' => 'sudo bash gateway.sh status',
        ];
    }

    /**
     * Sorted ports as runs, "25565-25600, 25700"; the same port on several IPs counts once.
     *
     * @param  list<int>  $ports
     */
    public static function summarize(array $ports): string
    {
        $ports = array_values(array_unique($ports));
        sort($ports);

        $runs = [];
        $start = null;
        $previous = null;
        foreach ($ports as $port) {
            if ($start !== null && $port === $previous + 1) {
                $previous = $port;

                continue;
            }

            if ($start !== null) {
                $runs[] = $start === $previous ? (string) $start : $start.'-'.$previous;
            }

            $start = $port;
            $previous = $port;
        }

        if ($start !== null) {
            $runs[] = $start === $previous ? (string) $start : $start.'-'.$previous;
        }

        return implode(', ', $runs);
    }

    /**
     * @return GatewaySettings
     */
    public function settings(Node $node): array
    {
        $ports = is_string($node->gateway_ports) && $node->gateway_ports !== '' ? $node->gateway_ports : GatewayPorts::DEFAULT;
        $file = self::scriptFile();
        $hash = is_file($file) ? hash_file('sha256', $file) : false;
        $script = ['url' => mb_rtrim(config()->string('app.url'), '/').self::SCRIPT_PATH, 'sha256' => is_string($hash) ? $hash : null];

        return [
            'enabled' => $node->gateway_enabled === true,
            'active' => $node->hasGateway(),
            'host' => is_string($node->gateway_host) && $node->gateway_host !== '' ? $node->gateway_host : null,
            'ports' => $ports,
            'ranges' => GatewayPorts::parse($ports),
            'script' => $script,
            'commands' => self::commands($script, $ports),
        ];
    }

    /**
     * Which of the node's allocations fall inside the ports (the stored ones unless others are
     * given), and the servers using a port outside them.
     *
     * @return GatewayCheck
     */
    public function check(Node $node, ?string $ports = null): array
    {
        $ranges = GatewayPorts::parse($ports ?? (string) $node->gateway_ports);
        $inside = [];
        $outside = [];
        $assigned = ['in' => 0, 'out' => 0];
        $servers = [];

        $allocations = Allocation::query()->where('node_id', $node->id)->orderBy('port')->get(['id', 'port', 'server_id']);
        $names = Server::query()->whereIn('id', $allocations->pluck('server_id')->filter()->unique()->values()->all())->get(['id', 'name', 'allocation_id'])->keyBy('id');

        foreach ($allocations as $allocation) {
            $port = (int) $allocation->port;
            $forwarded = GatewayPorts::contains($ranges, $port);
            $forwarded ? $inside[] = $port : $outside[] = $port;

            if ($allocation->server_id === null) {
                continue;
            }

            $assigned[$forwarded ? 'in' : 'out']++;
            $server = $names->get($allocation->server_id);
            if (! $forwarded && $server instanceof Server && count($servers) < self::MAX_LISTED_SERVERS) {
                $servers[] = ['server_id' => $server->id, 'server_name' => $server->name, 'port' => $port, 'primary' => $server->allocation_id === $allocation->id];
            }
        }

        return [
            'ranges' => $ranges,
            'forwarded' => ['total' => count($inside), 'assigned' => $assigned['in'], 'ports' => self::summarize($inside)],
            'not_forwarded' => ['total' => count($outside), 'assigned' => $assigned['out'], 'ports' => self::summarize($outside)],
            'servers_not_forwarded' => $servers,
        ];
    }
}
