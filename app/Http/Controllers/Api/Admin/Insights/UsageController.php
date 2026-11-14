<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Insights;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Insights\GetUsageRequest;
use Pterodactyl\Services\Insights\NodeUsageService;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Insights', 'Live resource use and storage across the panel and its nodes.')]
class UsageController extends AdminApiController
{
    private const array USAGE_EXAMPLE = [
        'nodes' => [[
            'id' => 1,
            'name' => 'Node 1',
            'status' => 'online',
            'cpu' => ['used' => 250.5, 'capacity' => 2100],
            'memory' => ['used_bytes' => 4_294_967_296, 'allocated_bytes' => 8_589_934_592, 'capacity_bytes' => 34_359_738_368],
            'disk' => ['used_bytes' => 21_474_836_480, 'allocated_bytes' => 53_687_091_200, 'capacity_bytes' => 536_870_912_000],
            'servers_online' => 1,
            'servers_total' => 1,
            'servers' => [[
                'id' => 1,
                'name' => 'Survival',
                'state' => 'running',
                'cpu' => 250.5,
                'cpu_limit' => 400,
                'memory_bytes' => 4_294_967_296,
                'memory_limit_bytes' => 8_589_934_592,
                'disk_bytes' => 21_474_836_480,
                'disk_limit_bytes' => 53_687_091_200,
            ]],
        ]],
    ];

    /**
     * Return live node and server resource use.
     */
    #[Endpoint('Get live usage', "Returns each node's live CPU, memory and disk use with its servers, read from Wings and cached for a few seconds. CPU is a percentage where 100 is one thread. Unreachable nodes are reported as offline. Nodes are identified by id and name only.")]
    #[ScribeResponse(self::USAGE_EXAMPLE, description: 'Live usage returned.')]
    #[ResponseField('nodes', 'object[]', 'Every node, ordered by name.', required: true)]
    #[ResponseField('nodes.id', 'integer', 'The node ID.', required: true)]
    #[ResponseField('nodes.name', 'string', 'The node name.', required: true)]
    #[ResponseField('nodes.status', 'string', 'Whether Wings answered: online or offline.', required: true, enum: ['online', 'offline'])]
    #[ResponseField('nodes.cpu', 'object', 'CPU in use as a percentage where 100 is one thread.', required: true)]
    #[ResponseField('nodes.cpu.used', 'number', "CPU the node's servers use.", required: true)]
    #[ResponseField('nodes.cpu.capacity', 'integer', "The node's CPU threads times 100, when Wings reports them.", nullable: true)]
    #[ResponseField('nodes.memory', 'object', 'Memory in bytes.', required: true)]
    #[ResponseField('nodes.memory.used_bytes', 'integer', "Memory the node's servers use.", required: true)]
    #[ResponseField('nodes.memory.allocated_bytes', 'integer', "Memory allocated to the node's servers.", required: true)]
    #[ResponseField('nodes.memory.capacity_bytes', 'integer', "The node's configured memory.", required: true)]
    #[ResponseField('nodes.disk', 'object', 'Disk in bytes.', required: true)]
    #[ResponseField('nodes.disk.used_bytes', 'integer', "Disk the node's servers use, or null while the node is offline.", nullable: true)]
    #[ResponseField('nodes.disk.allocated_bytes', 'integer', "Disk allocated to the node's servers.", required: true)]
    #[ResponseField('nodes.disk.capacity_bytes', 'integer', "The node's configured disk.", required: true)]
    #[ResponseField('nodes.servers_online', 'integer', 'Servers Wings reports as running.', required: true)]
    #[ResponseField('nodes.servers_total', 'integer', 'Servers on the node.', required: true)]
    #[ResponseField('nodes.servers', 'object[]', "The node's servers, ordered by name.", required: true)]
    #[ResponseField('nodes.servers.id', 'integer', 'The server ID.', required: true)]
    #[ResponseField('nodes.servers.name', 'string', 'The server name.', required: true)]
    #[ResponseField('nodes.servers.state', 'string', 'The Wings power state; unknown when Wings has no reading and offline when the node is unreachable.', required: true)]
    #[ResponseField('nodes.servers.cpu', 'number', 'CPU in use as a percentage where 100 is one thread.', required: true)]
    #[ResponseField('nodes.servers.cpu_limit', 'integer', 'The CPU limit as a percentage, or null when unlimited.', nullable: true)]
    #[ResponseField('nodes.servers.memory_bytes', 'integer', 'Memory in use.', required: true)]
    #[ResponseField('nodes.servers.memory_limit_bytes', 'integer', 'The memory limit, or null when unlimited.', nullable: true)]
    #[ResponseField('nodes.servers.disk_bytes', 'integer', 'Disk in use, or null without a reading.', nullable: true)]
    #[ResponseField('nodes.servers.disk_limit_bytes', 'integer', 'The disk limit, or null when unlimited.', nullable: true)]
    public function __invoke(GetUsageRequest $request, NodeUsageService $usage): JsonResponse
    {
        return new JsonResponse(['nodes' => $usage->nodes()]);
    }
}
