<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Nodes;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Nodes\ApplyMinecraftEntranceProxyProtocolRequest;
use Pterodactyl\Http\Requests\Api\Admin\Nodes\GetMinecraftEntranceRequest;
use Pterodactyl\Http\Requests\Api\Admin\Nodes\UpdateMinecraftEntranceRequest;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Entrance\EntranceHostnames;
use Pterodactyl\Services\Entrance\EntranceSettings;
use Pterodactyl\Services\Entrance\EntranceTunnelService;
use Pterodactyl\Services\Entrance\MinecraftEntrance;
use Pterodactyl\Services\Entrance\ProxyProtocolApplier;
use Pterodactyl\Services\Gateway\GatewayHost;

/**
 * The node's shared Minecraft entrance: every Minecraft Java server on it is joined as
 * `<name>.<domain>` through one playit.gg tunnel or the game gateway, routed by host name by a
 * Gate Lite proxy on the node (scripts/monoforge/mc-router.sh).
 */
#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Nodes', 'Create, update, retrieve, and delete Wings nodes.')]
class MinecraftEntranceController extends AdminApiController
{
    private const array EXAMPLE = [
        'enabled' => true,
        'active' => true,
        'domain' => 'play.example.com',
        'mode' => 'playit',
        'entrance_port' => 25565,
        'proxy_port' => 25500,
        'listen_port' => 25500,
        'public_port' => 41234,
        'proxy_protocol' => false,
        'srv' => true,
        'tunnel_address' => 'abc-def.gl.at.ply.gg:41234',
        'tunnel_error' => null,
        'synced_at' => '2026-11-16T10:00:00+00:00',
        'servers' => 4,
        'example_address' => 'survival.play.example.com',
        'gateway_forwards_entrance' => null,
        'dns' => [
            ['type' => 'CNAME', 'name' => '*.play.example.com', 'value' => 'abc-def.gl.at.ply.gg', 'note' => 'DNS only (grey cloud on Cloudflare).'],
            ['type' => 'SRV', 'name' => '_minecraft._tcp.survival.play.example.com', 'value' => '0 5 41234 survival.play.example.com', 'note' => 'Lets players leave out the port; the target is the same name.'],
        ],
        'script' => ['url' => 'https://panel.example.com/scripts/mc-router.sh', 'sha256' => null],
        'commands' => [
            'download' => 'curl -fsSL -o mc-router.sh https://panel.example.com/scripts/mc-router.sh',
            'install' => 'sudo bash mc-router.sh install',
            'status' => 'sudo bash mc-router.sh status',
            'remove' => 'sudo bash mc-router.sh remove',
        ],
    ];

    private const array APPLY_EXAMPLE = [
        'applied' => 2,
        'restart' => 1,
        'unsupported' => 1,
        'failed' => 0,
        'servers' => [
            ['server_id' => 12, 'server_name' => 'Survival world', 'result' => 'applied'],
            ['server_id' => 14, 'server_name' => 'Fabric SMP', 'result' => 'unsupported'],
        ],
    ];

    public function __construct(private readonly EntranceSettings $settings, private readonly EntranceTunnelService $tunnels) {}

    #[Endpoint('Get node Minecraft entrance', "Returns the node's shared Minecraft entrance: Minecraft Java servers on the node are joined as `<name>.<domain>` through one public entrance, a playit.gg tunnel (`mode: playit`) or the game gateway (`mode: gateway`), and a Gate Lite proxy on the node routes each player by the host name in the handshake. Includes the DNS records to create, the mc-router.sh commands, how many servers are routed and when the node last fetched its routes.")]
    #[ResponseField('enabled', 'boolean', 'Whether the entrance is turned on.')]
    #[ResponseField('active', 'boolean', 'Whether it is in use: on, with a domain and a usable way in (the gateway, or a playit agent).')]
    #[ResponseField('domain', 'string', 'The base domain, such as play.example.com.', nullable: true)]
    #[ResponseField('mode', 'string', 'How players reach the entrance.', enum: ['playit', 'gateway', null], nullable: true)]
    #[ResponseField('entrance_port', 'integer', 'The public port behind the gateway (25565 lets players leave it out); the proxy listens on it too.')]
    #[ResponseField('proxy_port', 'integer', 'The local port the proxy listens on behind playit.')]
    #[ResponseField('listen_port', 'integer', 'The port the proxy listens on now.')]
    #[ResponseField('public_port', 'integer', 'The port players reach the entrance on; null while the playit tunnel has no address.', nullable: true)]
    #[ResponseField('proxy_protocol', 'boolean', "Whether the proxy forwards players' real IPs (PROXY protocol) to servers that can read it.")]
    #[ResponseField('srv', 'boolean', 'Whether the SRV records exist, so addresses are shown without the playit port.')]
    #[ResponseField('tunnel_address', 'string', 'The address of the playit tunnel to the proxy.', nullable: true)]
    #[ResponseField('tunnel_error', 'string', 'Why the playit tunnel could not be made or read.', nullable: true)]
    #[ResponseField('synced_at', 'string', 'When mc-router.sh on the node last fetched its routes.', nullable: true)]
    #[ResponseField('servers', 'integer', 'Servers routed now (Minecraft Java, installed and not suspended).')]
    #[ResponseField('example_address', 'string', 'The address of one routed server.', nullable: true)]
    #[ResponseField('gateway_forwards_entrance', description: 'Behind the gateway: whether it forwards the entrance port.', schema: ['type' => 'boolean', 'nullable' => true, 'example' => null])]
    #[ResponseField('dns', description: 'The DNS records to create for the domain.', schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['type', 'name', 'value', 'note'],
            'properties' => [
                'type' => ['type' => 'string', 'example' => 'CNAME'],
                'name' => ['type' => 'string', 'example' => '*.play.example.com'],
                'value' => ['type' => 'string', 'example' => 'abc-def.gl.at.ply.gg'],
                'note' => ['type' => 'string', 'example' => 'DNS only (grey cloud on Cloudflare).'],
            ],
        ],
    ])]
    #[ResponseField('script', 'object', 'The node script the commands download.')]
    #[ResponseField('script.url', 'string', 'Where the panel serves mc-router.sh.')]
    #[ResponseField('script.sha256', 'string', 'The SHA-256 of mc-router.sh.', nullable: true)]
    #[ResponseField('commands', 'object', 'The mc-router.sh commands, run with sudo on the node.')]
    #[ResponseField('commands.download', 'string', 'Downloads mc-router.sh and checks it.')]
    #[ResponseField('commands.install', 'string', 'Installs Gate and the route sync timer.')]
    #[ResponseField('commands.status', 'string', 'Shows the proxy, the last sync and the routes.')]
    #[ResponseField('commands.remove', 'string', 'Removes the proxy from the node.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Minecraft entrance returned.')]
    public function show(GetMinecraftEntranceRequest $request, Node $node): JsonResponse
    {
        return new JsonResponse($this->settings->payload($node));
    }

    #[Endpoint('Update node Minecraft entrance', "Turns the node's shared Minecraft entrance on or off and sets its domain, how players reach it and its ports. While it is on, Minecraft Java servers on the node get no playit.gg tunnel for their game port (extra ports keep theirs) and are joined as `<name>.<domain>`. With `mode: playit` the panel keeps one playit tunnel to 127.0.0.1:<proxy_port>; with `mode: gateway` the gateway VPS must forward `entrance_port`, which the proxy listens on. The listening port must not be an allocation of the node or a Wings port.")]
    #[BodyParam('enabled', 'boolean', 'Whether the entrance is on.', required: true, example: true)]
    #[BodyParam('domain', 'string', 'The base domain the server names go under; required while enabled.', required: false, example: 'play.example.com')]
    #[BodyParam('mode', 'string', 'playit or gateway; required while enabled.', required: false, example: 'playit', enum: ['playit', 'gateway'])]
    #[BodyParam('entrance_port', 'integer', 'Behind the gateway: the public port (default 25565).', required: false, example: 25565)]
    #[BodyParam('proxy_port', 'integer', 'Behind playit: the local port the proxy listens on (default 25500).', required: false, example: 25500)]
    #[BodyParam('proxy_protocol', 'boolean', "Forward players' real IPs to servers that can read PROXY protocol (Paper, Purpur, Folia).", required: false, example: false)]
    #[BodyParam('srv', 'boolean', 'The per-server SRV records exist: show addresses without the playit port.', required: false, example: false)]
    #[ResponseField('enabled', 'boolean', 'Whether the entrance is turned on.')]
    #[ResponseField('active', 'boolean', 'Whether it is in use.')]
    #[ResponseField('domain', 'string', 'The base domain.', nullable: true)]
    #[ResponseField('mode', 'string', 'How players reach the entrance.', enum: ['playit', 'gateway', null], nullable: true)]
    #[ResponseField('entrance_port', 'integer', 'The public port behind the gateway.')]
    #[ResponseField('proxy_port', 'integer', 'The local proxy port behind playit.')]
    #[ResponseField('listen_port', 'integer', 'The port the proxy listens on now.')]
    #[ResponseField('public_port', 'integer', 'The port players reach the entrance on.', nullable: true)]
    #[ResponseField('proxy_protocol', 'boolean', "Whether players' real IPs are forwarded.")]
    #[ResponseField('srv', 'boolean', 'Whether the SRV records exist.')]
    #[ResponseField('tunnel_address', 'string', 'The address of the playit tunnel.', nullable: true)]
    #[ResponseField('tunnel_error', 'string', 'Why the playit tunnel failed.', nullable: true)]
    #[ResponseField('synced_at', 'string', 'When the node last fetched its routes.', nullable: true)]
    #[ResponseField('servers', 'integer', 'Servers routed now.')]
    #[ResponseField('example_address', 'string', 'The address of one routed server.', nullable: true)]
    #[ResponseField('gateway_forwards_entrance', description: 'Behind the gateway: whether it forwards the entrance port.', schema: ['type' => 'boolean', 'nullable' => true, 'example' => null])]
    #[ResponseField('dns', description: 'The DNS records to create.', schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['type', 'name', 'value', 'note'],
            'properties' => [
                'type' => ['type' => 'string', 'example' => 'CNAME'],
                'name' => ['type' => 'string', 'example' => '*.play.example.com'],
                'value' => ['type' => 'string', 'example' => 'abc-def.gl.at.ply.gg'],
                'note' => ['type' => 'string', 'example' => 'DNS only.'],
            ],
        ],
    ])]
    #[ResponseField('script', 'object', 'The node script.')]
    #[ResponseField('script.url', 'string', 'Where the panel serves mc-router.sh.')]
    #[ResponseField('script.sha256', 'string', 'The SHA-256 of mc-router.sh.', nullable: true)]
    #[ResponseField('commands', 'object', 'The mc-router.sh commands.')]
    #[ResponseField('commands.download', 'string', 'Downloads mc-router.sh.')]
    #[ResponseField('commands.install', 'string', 'Installs Gate and the sync timer.')]
    #[ResponseField('commands.status', 'string', 'Shows the proxy status.')]
    #[ResponseField('commands.remove', 'string', 'Removes the proxy.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Minecraft entrance saved.')]
    public function update(UpdateMinecraftEntranceRequest $request, Node $node): JsonResponse
    {
        $input = [
            'enabled' => $request->boolean('enabled'),
            'domain' => $request->filled('domain') ? GatewayHost::normalize($request->string('domain')->toString()) : (is_string($node->mc_entrance_domain) ? $node->mc_entrance_domain : null),
            'mode' => $request->filled('mode') ? $request->string('mode')->toString() : (is_string($node->mc_entrance_mode) ? $node->mc_entrance_mode : null),
            'entrance_port' => $request->filled('entrance_port') ? $request->integer('entrance_port') : (int) $node->mc_entrance_port,
            'proxy_port' => $request->filled('proxy_port') ? $request->integer('proxy_port') : (int) $node->mc_entrance_proxy_port,
            'proxy_protocol' => $request->has('proxy_protocol') ? $request->boolean('proxy_protocol') : $node->mc_entrance_proxy_protocol === true,
            'srv' => $request->has('srv') ? $request->boolean('srv') : $node->mc_entrance_srv === true,
        ];

        $errors = EntranceSettings::errors($node, $input);
        throw_if($errors !== [], ValidationException::withMessages($errors));

        $node->forceFill([
            'mc_entrance_enabled' => $input['enabled'],
            'mc_entrance_domain' => $input['domain'],
            'mc_entrance_mode' => $input['mode'],
            'mc_entrance_port' => $input['entrance_port'],
            'mc_entrance_proxy_port' => $input['proxy_port'],
            'mc_entrance_proxy_protocol' => $input['proxy_protocol'],
            'mc_entrance_srv' => $input['srv'],
        ]);

        $proxyPortChanged = $node->isDirty('mc_entrance_proxy_port');
        if ($node->isDirty()) {
            $node->save();

            Activity::event('admin:node.minecraft-entrance')
                ->subject($node)
                ->property('name', $node->name)
                ->property('state', $input['enabled'] ? 'on' : 'off')
                ->log();
        }

        // The one playit tunnel points at the proxy port: a new port needs a new tunnel.
        if ($proxyPortChanged) {
            $this->tunnels->release($node);
        }

        $this->tunnels->ensure($node);

        // Every routed server gets its name now, so the DNS records list them all.
        foreach (resolve(MinecraftEntrance::class)->routedServers($node) as $server) {
            EntranceHostnames::for($server);
        }

        return new JsonResponse($this->settings->payload($node->refresh()));
    }

    #[Endpoint('Apply Minecraft entrance PROXY protocol', 'Sets `proxies.proxy-protocol: true` in config/paper-global.yml of every routed Paper, Purpur or Folia server on the node, through Wings (the old file is kept as paper-global.yml.monoforge-backup). The proxy only sends PROXY protocol to a server once it is stopped or has restarted since the change (`applied`); a running one reports `restart` until then (apply again after restarting it). Vanilla, Fabric, Forge and other servers are left alone (`unsupported`) and never get PROXY protocol. Needs `proxy_protocol` on.')]
    #[ResponseField('applied', 'integer', 'Servers that now get real player IPs.')]
    #[ResponseField('restart', 'integer', 'Servers changed that must restart first; apply again afterwards.')]
    #[ResponseField('unsupported', 'integer', 'Servers whose software cannot read PROXY protocol.')]
    #[ResponseField('failed', 'integer', 'Servers Wings could not change.')]
    #[ResponseField('servers', description: 'Each routed server with its result.', schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['server_id', 'server_name', 'result'],
            'properties' => [
                'server_id' => ['type' => 'integer', 'example' => 12],
                'server_name' => ['type' => 'string', 'example' => 'Survival world'],
                'result' => ['type' => 'string', 'enum' => ['applied', 'restart', 'unsupported', 'failed'], 'example' => 'applied'],
            ],
        ],
    ])]
    #[ScribeResponse(self::APPLY_EXAMPLE, description: 'PROXY protocol applied.')]
    public function applyProxyProtocol(ApplyMinecraftEntranceProxyProtocolRequest $request, Node $node, MinecraftEntrance $entrance, ProxyProtocolApplier $applier): JsonResponse
    {
        throw_if($node->mc_entrance_proxy_protocol !== true || ! $node->hasMinecraftEntrance(), ValidationException::withMessages([
            'proxy_protocol' => "Turn on the node's Minecraft entrance and \"Forward player IPs\" first.",
        ]));

        $counts = [ProxyProtocolApplier::APPLIED => 0, ProxyProtocolApplier::RESTART => 0, ProxyProtocolApplier::UNSUPPORTED => 0, ProxyProtocolApplier::FAILED => 0];
        $servers = [];
        foreach ($entrance->routedServers($node) as $server) {
            $result = $applier->apply($server);
            $counts[$result]++;
            $servers[] = ['server_id' => $server->id, 'server_name' => $server->name, 'result' => $result];
        }

        Activity::event('admin:node.minecraft-entrance-proxy-protocol')
            ->subject($node)
            ->property('name', $node->name)
            ->property('count', $counts[ProxyProtocolApplier::APPLIED])
            ->log();

        return new JsonResponse([...$counts, 'servers' => $servers]);
    }
}
