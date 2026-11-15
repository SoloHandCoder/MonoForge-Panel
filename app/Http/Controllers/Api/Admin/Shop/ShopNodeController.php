<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Shop;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Shop\GetShopRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\UpdateShopNodeRequest;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Billing\CapacityService;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Shop', 'The token shop catalog: categories, plans, billing periods, price multipliers, add-ons and limits.')]
class ShopNodeController extends AdminApiController
{
    private const array EXAMPLE = [
        'id' => 1,
        'name' => 'node-1',
        'location' => ['id' => 1, 'short' => 'de', 'long' => 'Frankfurt'],
        'public' => true,
        'maintenance' => false,
        'shop_enabled' => true,
        'shop_servers' => 4,
        'limits' => ['memory' => 32768, 'disk' => 512000, 'cpu' => 1600],
        'used' => ['memory' => 8192, 'disk' => 40960, 'cpu' => 400],
    ];

    public function __construct(private readonly CapacityService $capacity) {}

    #[Endpoint('List shop nodes', 'Returns every node with whether the store may sell new servers on it, its location, how many servers bought in the store it runs, its own shop limits and what the shop servers on it use. The store offers a node only when it is available in the shop and public.')]
    #[ResponseField('data.limits', 'object', 'The most the shop sells on this node, in MB of memory and disk and percent of CPU; 0 is no limit. The shop-wide limits apply as well.')]
    #[ResponseField('data.used', 'object', 'What the active and suspended shop servers on this node use.')]
    #[ScribeResponse(['data' => [self::EXAMPLE]], description: 'Nodes returned.')]
    public function index(GetShopRequest $request): JsonResponse
    {
        $used = $this->capacity->usedByNode();

        return new JsonResponse(['data' => $this->nodes()->get()->map(fn (Node $node): array => $this->present($node, $used))->all()]);
    }

    #[Endpoint('Update shop node', "Makes a node available in the shop, or takes it out, and sets how much the shop may sell on it. Taking a node out stops new sales on it; servers already sold there keep running and renewing. Nodes are out of the shop until an administrator makes them available. A node at its limits shows as full in the store. The node's own memory and disk allocation does not limit the shop.")]
    #[ResponseField('limits', 'object', 'The most the shop sells on this node, in MB of memory and disk and percent of CPU; 0 is no limit.')]
    #[ResponseField('used', 'object', 'What the active and suspended shop servers on this node use.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Node updated.')]
    public function update(UpdateShopNodeRequest $request, Node $node): JsonResponse
    {
        if ($request->has('shop_enabled')) {
            $enabled = $request->boolean('shop_enabled');
            $node->forceFill(['shop_enabled' => $enabled])->save();

            Activity::event($enabled ? 'admin:shop.node.enable' : 'admin:shop.node.disable')->property('name', $node->name)->log();
        }

        $limits = $request->limits();
        if ($limits !== null) {
            $node->forceFill([
                'shop_limit_memory_mb' => $limits['memory'],
                'shop_limit_disk_mb' => $limits['disk'],
                'shop_limit_cpu_percent' => $limits['cpu'],
            ])->save();

            Activity::event('admin:shop.node.limits')->property(['name' => $node->name, ...$limits])->log();
        }

        return new JsonResponse($this->present($this->nodes()->findOrFail($node->id), [$node->id => $this->capacity->usedOn($node->id)]));
    }

    /**
     * Nodes by name, with their location and the number of servers that have a shop subscription.
     *
     * @return Builder<Node>
     */
    private function nodes(): Builder
    {
        return Node::query()
            ->with('location')
            ->withCount(['servers as shop_servers_count' => fn (Builder $query) => $query->whereHas('subscription')])
            ->orderBy('name');
    }

    /**
     * @param  array<int, array{memory: int, disk: int, cpu: int}>  $used  what the shop servers use, by node id
     * @return array<string, mixed>
     */
    private function present(Node $node, array $used): array
    {
        $shopServers = $node->getAttribute('shop_servers_count');

        return [
            'id' => $node->id,
            'name' => $node->name,
            'location' => ['id' => $node->location->id, 'short' => $node->location->short, 'long' => $node->location->long],
            'public' => $node->public,
            'maintenance' => $node->isUnderMaintenance(),
            'shop_enabled' => (bool) $node->shop_enabled,
            'shop_servers' => is_numeric($shopServers) ? (int) $shopServers : 0,
            'limits' => CapacityService::nodeLimits($node),
            'used' => $used[$node->id] ?? ['memory' => 0, 'disk' => 0, 'cpu' => 0],
        ];
    }
}
