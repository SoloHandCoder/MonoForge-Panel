<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Nodes;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Nodes\CheckGatewayRequest;
use Pterodactyl\Http\Requests\Api\Admin\Nodes\GetGatewaySettingsRequest;
use Pterodactyl\Http\Requests\Api\Admin\Nodes\UpdateGatewaySettingsRequest;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Gateway\GameGatewayService;
use Pterodactyl\Services\Gateway\GatewayHost;
use Pterodactyl\Services\Gateway\GatewayPorts;

/**
 * The node's game gateway: a VPS (such as Oracle Cloud's free tier) forwarding a port range
 * through WireGuard to the node with scripts/monoforge/gateway.sh. While it is on, players join
 * `<host>:<port>` and the node's servers get no playit.gg tunnels.
 */
#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Nodes', 'Create, update, retrieve, and delete Wings nodes.')]
class GatewayController extends AdminApiController
{
    private const array SETTINGS_EXAMPLE = [
        'enabled' => true,
        'active' => true,
        'host' => 'play.example.com',
        'ports' => '25565-25700',
        'ranges' => [[25565, 25700]],
        'script' => [
            'url' => 'https://panel.example.com/scripts/gateway.sh',
            'sha256' => '9f2c1d7e4b3a5c6d8e0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d',
        ],
        'commands' => [
            'download' => "curl -fsSL -o gateway.sh https://panel.example.com/scripts/gateway.sh\necho \"9f2c1d7e4b3a5c6d8e0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d  gateway.sh\" | sha256sum -c -",
            'vps_init' => 'sudo bash gateway.sh vps-init --ports 25565-25700',
            'node_init' => 'sudo bash gateway.sh node-init --gateway <VPS public IP> --gateway-key <VPS public key>',
            'vps_add_node' => 'sudo bash gateway.sh vps-add-node --node-key <node public key>',
            'status' => 'sudo bash gateway.sh status',
        ],
    ];

    private const array CHECK_EXAMPLE = [
        'ranges' => [[25565, 25700]],
        'forwarded' => ['total' => 136, 'assigned' => 12, 'ports' => '25565-25700'],
        'not_forwarded' => ['total' => 2, 'assigned' => 1, 'ports' => '24454, 30000'],
        'servers_not_forwarded' => [
            ['server_id' => 12, 'server_name' => 'Survival world', 'port' => 24454, 'primary' => false],
        ],
    ];

    public function __construct(private readonly GameGatewayService $gateway) {}

    #[Endpoint('Get node game gateway', 'Returns the game gateway of the node: whether it is on (`enabled`) and in use (`active`: on, with a host and ports), the public host players join, the forwarded ports as typed and as ranges, and the gateway.sh commands filled with those ports. The VPS IP and the WireGuard public keys are printed by the script and are placeholders here; the panel never stores the VPS IP.')]
    #[ResponseField('enabled', 'boolean', 'Whether the gateway is turned on.')]
    #[ResponseField('active', 'boolean', 'Whether players join through the gateway now: it is on and has a host and ports. The node gets no playit.gg tunnels then.')]
    #[ResponseField('host', 'string', 'The public host players join, such as play.example.com, or null when none is set.', nullable: true)]
    #[ResponseField('ports', 'string', 'The forwarded ports as gateway.sh takes them; the script default 25565-25700 when none are set.')]
    #[ResponseField('ranges', description: 'The forwarded ports as [from, to] pairs.', schema: ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer'], 'example' => [25565, 25700]]])]
    #[ResponseField('script', 'object', 'The gateway script the commands download.')]
    #[ResponseField('commands', 'object', 'The gateway.sh commands, filled with the ports.')]
    #[ResponseField('script.url', 'string', 'Where the panel serves gateway.sh.')]
    #[ResponseField('script.sha256', 'string', 'The SHA-256 of gateway.sh, to check the download.', nullable: true)]
    #[ResponseField('commands.download', 'string', 'Downloads gateway.sh and checks it, on both machines.')]
    #[ResponseField('commands.vps_init', 'string', 'Run on the VPS first: installs WireGuard and forwards the ports.')]
    #[ResponseField('commands.node_init', 'string', 'Run on the node with the IP and key vps-init printed.')]
    #[ResponseField('commands.vps_add_node', 'string', 'Run on the VPS with the key node-init printed.')]
    #[ResponseField('commands.status', 'string', 'Shows the WireGuard link and the forwarded ports, on either machine.')]
    #[ScribeResponse(self::SETTINGS_EXAMPLE, description: 'Game gateway returned.')]
    public function show(GetGatewaySettingsRequest $request, Node $node): JsonResponse
    {
        return new JsonResponse($this->gateway->settings($node));
    }

    #[Endpoint('Update node game gateway', "Turns the node's game gateway on or off and sets its public host and forwarded ports. While it is on, players join `<host>:<port>` for every server whose port is forwarded (Minecraft Java on 25565 without the port), no playit.gg tunnel is created, synced or retried for the node's servers, and existing ones are left on playit but shown as replaced by the gateway. Turning it off brings the playit tunnels back. The host must be a host name or a public IPv4 address and never the node's own FQDN; the ports take gateway.sh's syntax and limits: 1 to 7 ports or ranges between 1024 and 65535, never SSH (22) or WireGuard (51820).")]
    #[BodyParam('enabled', 'boolean', "Whether players join the node's servers through the gateway.", required: true, example: true)]
    #[BodyParam('host', 'string', "The public host players join: a host name (an A record pointing at the VPS, DNS only) or the VPS's public IPv4 address. Required while enabled.", required: false, example: 'play.example.com')]
    #[BodyParam('ports', 'string', 'The ports gateway.sh forwards, such as 25565-25700,7777. Required while enabled.', required: false, example: '25565-25700')]
    #[ResponseField('enabled', 'boolean', 'Whether the gateway is turned on.')]
    #[ResponseField('active', 'boolean', 'Whether players join through the gateway now.')]
    #[ResponseField('host', 'string', 'The public host players join.', nullable: true)]
    #[ResponseField('ports', 'string', 'The forwarded ports.')]
    #[ResponseField('ranges', description: 'The forwarded ports as [from, to] pairs.', schema: ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer'], 'example' => [25565, 25700]]])]
    #[ResponseField('script', 'object', 'The gateway script the commands download.')]
    #[ResponseField('commands', 'object', 'The gateway.sh commands, filled with the ports.')]
    #[ResponseField('script.url', 'string', 'Where the panel serves gateway.sh.')]
    #[ResponseField('script.sha256', 'string', 'The SHA-256 of gateway.sh.', nullable: true)]
    #[ResponseField('commands.download', 'string', 'Downloads gateway.sh and checks it.')]
    #[ResponseField('commands.vps_init', 'string', 'Run on the VPS first.')]
    #[ResponseField('commands.node_init', 'string', 'Run on the node next.')]
    #[ResponseField('commands.vps_add_node', 'string', 'Run on the VPS last.')]
    #[ResponseField('commands.status', 'string', 'Shows the link on either machine.')]
    #[ScribeResponse(self::SETTINGS_EXAMPLE, description: 'Game gateway saved.')]
    public function update(UpdateGatewaySettingsRequest $request, Node $node): JsonResponse
    {
        $node->gateway_enabled = $request->boolean('enabled');
        if ($request->filled('host')) {
            $node->gateway_host = GatewayHost::normalize($request->string('host')->toString());
        }

        if ($request->filled('ports')) {
            $node->gateway_ports = GatewayPorts::normalize($request->string('ports')->toString());
        }

        if ($node->isDirty(['gateway_enabled', 'gateway_host', 'gateway_ports'])) {
            $node->save();

            Activity::event('admin:node.gateway')
                ->subject($node)
                ->property('name', $node->name)
                ->property('state', $node->gateway_enabled ? 'on' : 'off')
                ->log();
        }

        return new JsonResponse($this->gateway->settings($node));
    }

    #[Endpoint('Check node game gateway ports', "Lists which of the node's allocations the gateway forwards and which it does not, as port runs, and the servers that use a port outside them (at most 100). Uses the given `ports`, or the stored ones. Nothing is tested on the network.")]
    #[BodyParam('ports', 'string', 'Ports to check instead of the stored ones, such as ones not saved yet.', required: false, example: '25565-25700')]
    #[ResponseField('ranges', description: 'The ports checked, as [from, to] pairs.', schema: ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer'], 'example' => [25565, 25700]]])]
    #[ResponseField('forwarded', 'object', 'The allocations inside the ports.')]
    #[ResponseField('not_forwarded', 'object', 'The allocations outside the ports.')]
    #[ResponseField('forwarded.total', 'integer', 'Allocations inside the ports.')]
    #[ResponseField('forwarded.assigned', 'integer', 'Of those, the ones a server uses.')]
    #[ResponseField('forwarded.ports', 'string', 'Their ports, as runs such as 25565-25600, 25700.')]
    #[ResponseField('not_forwarded.total', 'integer', 'Allocations outside the ports: players cannot reach them through the gateway.')]
    #[ResponseField('not_forwarded.assigned', 'integer', 'Of those, the ones a server uses.')]
    #[ResponseField('not_forwarded.ports', 'string', 'Their ports, as runs.')]
    #[ResponseField('servers_not_forwarded', description: 'Servers using a port outside the forwarded ones; `primary` is true for their main port.', schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['server_id', 'server_name', 'port', 'primary'],
            'properties' => [
                'server_id' => ['type' => 'integer', 'example' => 12],
                'server_name' => ['type' => 'string', 'example' => 'Survival world'],
                'port' => ['type' => 'integer', 'example' => 24454],
                'primary' => ['type' => 'boolean', 'example' => false],
            ],
        ],
    ])]
    #[ScribeResponse(self::CHECK_EXAMPLE, description: 'Allocations checked.')]
    public function check(CheckGatewayRequest $request, Node $node): JsonResponse
    {
        $ports = $request->filled('ports') ? $request->string('ports')->toString() : null;

        return new JsonResponse($this->gateway->check($node, $ports));
    }
}
