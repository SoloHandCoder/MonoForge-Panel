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
use Pterodactyl\Http\Requests\Api\Admin\Insights\GetStorageRequest;
use Pterodactyl\Services\Insights\NodeUsageService;
use Pterodactyl\Services\Insights\WebsiteStorageService;

/**
 * @phpstan-import-type NodeUsage from NodeUsageService
 */
#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Insights', 'Live resource use and storage across the panel and its nodes.')]
class StorageController extends AdminApiController
{
    /** How many of a node's largest servers are listed. */
    private const int TOP_SERVERS = 5;

    private const array STORAGE_EXAMPLE = [
        'nodes' => [[
            'id' => 1,
            'name' => 'Node 1',
            'status' => 'online',
            'used_bytes' => 21_474_836_480,
            'allocated_bytes' => 53_687_091_200,
            'capacity_bytes' => 536_870_912_000,
            'servers' => [[
                'id' => 1,
                'name' => 'Survival',
                'used_bytes' => 21_474_836_480,
                'limit_bytes' => 53_687_091_200,
            ]],
        ]],
        'website' => [
            'database_bytes' => 52_428_800,
            'storage_bytes' => 104_857_600,
            'storage_approximate' => false,
            'local_backups_bytes' => 10_737_418_240,
            'local_backups_count' => 4,
            'remote_backups_bytes' => 0,
            'remote_backups_count' => 0,
        ],
    ];

    /**
     * Return node and panel storage use.
     */
    #[Endpoint('Get storage usage', "Returns disk used by each node's servers against what is allocated and the node's limit, its largest servers, and the panel's own storage: database, storage folder and recorded backups. Nodes are identified by id and name only.")]
    #[ScribeResponse(self::STORAGE_EXAMPLE, description: 'Storage usage returned.')]
    #[ResponseField('nodes', 'object[]', 'Every node, ordered by name.', required: true)]
    #[ResponseField('nodes.id', 'integer', 'The node ID.', required: true)]
    #[ResponseField('nodes.name', 'string', 'The node name.', required: true)]
    #[ResponseField('nodes.status', 'string', 'Whether Wings answered: online or offline.', required: true, enum: ['online', 'offline'])]
    #[ResponseField('nodes.used_bytes', 'integer', "Disk the node's servers use, or null while the node is offline or none of its servers has a reading.", nullable: true)]
    #[ResponseField('nodes.allocated_bytes', 'integer', "Disk allocated to the node's servers.", required: true)]
    #[ResponseField('nodes.capacity_bytes', 'integer', "The node's configured disk.", required: true)]
    #[ResponseField('nodes.servers', 'object[]', 'Up to five servers using the most disk.', required: true)]
    #[ResponseField('nodes.servers.id', 'integer', 'The server ID.', required: true)]
    #[ResponseField('nodes.servers.name', 'string', 'The server name.', required: true)]
    #[ResponseField('nodes.servers.used_bytes', 'integer', 'Disk in use, or null without a reading.', nullable: true)]
    #[ResponseField('nodes.servers.limit_bytes', 'integer', 'The disk limit, or null when unlimited.', nullable: true)]
    #[ResponseField('website', 'object', 'Storage the panel itself uses.', required: true)]
    #[ResponseField('website.database_bytes', 'integer', "The panel database's data and index size, or null when the database does not report it.", nullable: true)]
    #[ResponseField('website.storage_bytes', 'integer', "The size of the panel's storage folder, or null when it cannot be read.", nullable: true)]
    #[ResponseField('website.storage_approximate', 'boolean', 'Whether the storage folder walk stopped early, so its size is a lower bound.', required: true)]
    #[ResponseField('website.local_backups_bytes', 'integer', 'Successful backups stored on the nodes.', required: true)]
    #[ResponseField('website.local_backups_count', 'integer', 'How many successful backups are stored on the nodes.', required: true)]
    #[ResponseField('website.remote_backups_bytes', 'integer', 'Successful backups stored in S3.', required: true)]
    #[ResponseField('website.remote_backups_count', 'integer', 'How many successful backups are stored in S3.', required: true)]
    public function __invoke(GetStorageRequest $request, NodeUsageService $usage, WebsiteStorageService $website): JsonResponse
    {
        return new JsonResponse([
            'nodes' => array_map($this->node(...), $usage->nodes()),
            'website' => $website->summary(),
        ]);
    }

    /**
     * @param  NodeUsage  $node
     * @return array{id: int, name: string, status: string, used_bytes: int|null, allocated_bytes: int, capacity_bytes: int, servers: list<array{id: int, name: string, used_bytes: int|null, limit_bytes: int|null}>}
     */
    private function node(array $node): array
    {
        $servers = $node['servers'];
        usort($servers, fn (array $a, array $b): int => [$b['disk_bytes'] ?? -1, $b['disk_limit_bytes'] ?? 0] <=> [$a['disk_bytes'] ?? -1, $a['disk_limit_bytes'] ?? 0]);

        return [
            'id' => $node['id'],
            'name' => $node['name'],
            'status' => $node['status'],
            'used_bytes' => $node['disk']['used_bytes'],
            'allocated_bytes' => $node['disk']['allocated_bytes'],
            'capacity_bytes' => $node['disk']['capacity_bytes'],
            'servers' => array_map(fn (array $server): array => [
                'id' => $server['id'],
                'name' => $server['name'],
                'used_bytes' => $server['disk_bytes'],
                'limit_bytes' => $server['disk_limit_bytes'],
            ], array_slice($servers, 0, self::TOP_SERVERS)),
        ];
    }
}
