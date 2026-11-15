<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Shop;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Shop\GetShopRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\UpdateShopNodeRequest;
use Pterodactyl\Models\Node;

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
    ];

    #[Endpoint('List shop nodes', 'Returns every node with whether the store may sell new servers on it, its location, and how many servers bought in the store it runs. The store offers a node only when it is available in the shop and public.')]
    #[ScribeResponse(['data' => [self::EXAMPLE]], description: 'Nodes returned.')]
    public function index(GetShopRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->nodes()->get()->map($this->present(...))->all()]);
    }

    #[Endpoint('Update shop node', 'Makes a node available in the shop, or takes it out. Taking a node out stops new sales on it; servers already sold there keep running and renewing. Nodes are out of the shop until an administrator makes them available.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Node updated.')]
    public function update(UpdateShopNodeRequest $request, Node $node): JsonResponse
    {
        $enabled = $request->boolean('shop_enabled');
        $node->forceFill(['shop_enabled' => $enabled])->save();

        Activity::event($enabled ? 'admin:shop.node.enable' : 'admin:shop.node.disable')->property('name', $node->name)->log();

        return new JsonResponse($this->present($this->nodes()->findOrFail($node->id)));
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
     * @return array<string, mixed>
     */
    private function present(Node $node): array
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
        ];
    }
}
