<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Entrance;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Gateway\GatewayHost;
use Pterodactyl\Services\Gateway\GatewayPorts;

/**
 * The "Shared Minecraft entrance" card of a node in the admin area: what it shows, and the
 * checks a change must pass.
 *
 * @phpstan-import-type DnsRecord from MinecraftEntrance
 *
 * @phpstan-type EntranceInput array{enabled: bool, domain: string|null, mode: string|null, entrance_port: int, proxy_port: int, proxy_protocol: bool, srv: bool}
 * @phpstan-type EntrancePayload array{enabled: bool, active: bool, domain: string|null, mode: string|null, entrance_port: int, proxy_port: int, listen_port: int, public_port: int|null, proxy_protocol: bool, srv: bool, tunnel_address: string|null, tunnel_error: string|null, synced_at: string|null, servers: int, example_address: string|null, gateway_forwards_entrance: bool|null, dns: list<DnsRecord>, script: array{url: string, sha256: string|null}, commands: array{download: string, install: string, status: string, remove: string}}
 */
class EntranceSettings
{
    /** Where the panel serves mc-router.sh (routes/base.php). */
    public const string SCRIPT_PATH = '/scripts/mc-router.sh';

    public function __construct(private readonly MinecraftEntrance $entrance) {}

    public static function scriptFile(): string
    {
        return base_path('scripts/monoforge/mc-router.sh');
    }

    /**
     * Why the settings cannot be saved on the node, by field; empty when they can.
     *
     * @param  EntranceInput  $input
     * @return array<string, string>
     */
    public static function errors(Node $node, array $input): array
    {
        $errors = [];
        $domain = $input['domain'];
        if ($domain !== null) {
            $hostError = GatewayHost::error($domain, $node);
            if ($hostError !== null || GatewayHost::isIpv4(GatewayHost::normalize($domain))) {
                $errors['domain'] = 'Enter the base domain the server names go under, such as play.example.com (not an IP address).';
            }
        }

        $listen = $input['mode'] === Node::ENTRANCE_GATEWAY ? $input['entrance_port'] : $input['proxy_port'];
        foreach (['entrance_port', 'proxy_port'] as $field) {
            $port = $input[$field];
            if ($port < GatewayPorts::MIN_PORT || $port > GatewayPorts::MAX_PORT || $port === GatewayPorts::WIREGUARD_PORT) {
                $errors[$field] = sprintf('Use a port between %d and %d, not %d.', GatewayPorts::MIN_PORT, GatewayPorts::MAX_PORT, GatewayPorts::WIREGUARD_PORT);
            }
        }

        $field = $input['mode'] === Node::ENTRANCE_GATEWAY ? 'entrance_port' : 'proxy_port';
        if (! isset($errors[$field])) {
            if (in_array($listen, [(int) $node->daemonListen, (int) $node->daemonSFTP], true)) {
                $errors[$field] = 'Wings already uses this port on the node.';
            } elseif (Allocation::query()->where('node_id', $node->id)->where('port', $listen)->exists()) {
                $errors[$field] = sprintf("Port %d is one of the node's allocations. The proxy needs a port no server can get: remove the allocation or pick another port.", $listen);
            }
        }

        if (! $input['enabled']) {
            return $errors;
        }

        if ($domain === null) {
            $errors['domain'] = 'Enter the base domain the server names go under, such as play.example.com.';
        }

        if ($input['mode'] === Node::ENTRANCE_GATEWAY) {
            if (! $node->hasGateway()) {
                $errors['mode'] = 'Turn on the game gateway of this node first, or expose the entrance through playit.gg.';
            } elseif (! $node->gatewayForwards($input['entrance_port']) && ! isset($errors['entrance_port'])) {
                $errors['entrance_port'] = sprintf('The game gateway does not forward port %d. Add it to the gateway ports (and to gateway.sh --ports) first.', $input['entrance_port']);
            }
        } elseif ($input['mode'] === Node::ENTRANCE_PLAYIT) {
            if ($node->hasGateway()) {
                $errors['mode'] = 'This node uses the game gateway: expose the entrance through the gateway.';
            } elseif (! $node->hasPlayitAgent()) {
                $errors['mode'] = 'Set up the playit.gg agent of this node first.';
            }
        } else {
            $errors['mode'] = 'Pick how players reach the entrance: playit or gateway.';
        }

        return $errors;
    }

    /**
     * @return EntrancePayload
     */
    public function payload(Node $node): array
    {
        $servers = $this->entrance->routedServers($node);
        $example = $servers === [] ? null : MinecraftEntrance::address($servers[0]);
        $file = self::scriptFile();
        $hash = is_file($file) ? hash_file('sha256', $file) : false;
        $url = mb_rtrim(config()->string('app.url'), '/').self::SCRIPT_PATH;

        $download = 'curl -fsSL -o mc-router.sh '.$url;
        if (is_string($hash)) {
            $download .= "\n".sprintf('echo "%s  mc-router.sh" | sha256sum -c -', $hash);
        }

        return [
            'enabled' => $node->mc_entrance_enabled === true,
            'active' => $node->hasMinecraftEntrance(),
            'domain' => is_string($node->mc_entrance_domain) && $node->mc_entrance_domain !== '' ? $node->mc_entrance_domain : null,
            'mode' => is_string($node->mc_entrance_mode) ? $node->mc_entrance_mode : null,
            'entrance_port' => (int) $node->mc_entrance_port,
            'proxy_port' => (int) $node->mc_entrance_proxy_port,
            'listen_port' => MinecraftEntrance::listenPort($node),
            'public_port' => $node->hasMinecraftEntrance() ? MinecraftEntrance::publicPort($node) : null,
            'proxy_protocol' => $node->mc_entrance_proxy_protocol === true,
            'srv' => $node->mc_entrance_srv === true,
            'tunnel_address' => $node->mc_entrance_address,
            'tunnel_error' => $node->mc_entrance_error,
            'synced_at' => $node->mc_entrance_synced_at?->toAtomString(),
            'servers' => count($servers),
            'example_address' => $example,
            'gateway_forwards_entrance' => $node->mc_entrance_mode === Node::ENTRANCE_GATEWAY ? $node->gatewayForwards((int) $node->mc_entrance_port) : null,
            'dns' => $this->entrance->dnsRecords($node),
            'script' => ['url' => $url, 'sha256' => is_string($hash) ? $hash : null],
            'commands' => [
                'download' => $download,
                'install' => 'sudo bash mc-router.sh install',
                'status' => 'sudo bash mc-router.sh status',
                'remove' => 'sudo bash mc-router.sh remove',
            ],
        ];
    }
}
