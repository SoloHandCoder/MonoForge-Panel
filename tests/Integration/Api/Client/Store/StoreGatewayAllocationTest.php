<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Store\StoreGatewayAllocationTest;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Contracts\Servers\CreatesServers;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Billing\ShopCategory;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Objects\DeploymentObject;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

use function pterodactylTestCase;

uses(ClientApiIntegrationTestCase::class);

/** Stands in for Wings: persists the server on the allocation the store picked. */
final class GatewayFakeServerCreation implements CreatesServers
{
    public function create(array $input, ?DeploymentObject $deployment = null): Server
    {
        $allocation = Allocation::query()->findOrFail($input['allocation_id']);
        $server = Server::factory()->create([
            ...Arr::only($input, ['external_id', 'name', 'owner_id', 'egg_id', 'image', 'startup', 'memory', 'swap', 'disk', 'io', 'cpu', 'database_limit', 'allocation_limit', 'backup_limit', 'allocation_id']),
            'node_id' => $allocation->node_id,
        ]);
        $allocation->forceFill(['server_id' => $server->id])->save();

        return $server;
    }
}

beforeEach(function (): void {
    Cache::flush();
    config(['monoforge.shop.enabled' => true, 'monoforge.shop.limits' => ['memory_mb' => 0, 'disk_mb' => 0, 'cpu_percent' => 0]]);
    $this->app->instance(CreatesServers::class, new GatewayFakeServerCreation);

    $this->location = Location::factory()->create();
    $this->node = Node::factory()->create(['location_id' => $this->location->id, 'name' => 'Gateway node', 'shop_enabled' => true, 'memory' => 100000, 'disk' => 1000000]);
    $this->node->forceFill(['gateway_enabled' => true, 'gateway_host' => 'play.example.com', 'gateway_ports' => '25565-25600'])->save();

    $this->egg = Egg::query()->where('author', 'support@pterodactyl.io')->where('name', 'Bungeecord')->firstOrFail();
    $category = ShopCategory::query()->forceCreate(['name' => 'Minecraft', 'slug' => 'minecraft-'.uniqid(), 'sort' => 0, 'visible' => true])->id;
    $this->plan = ShopPlan::query()->forceCreate([
        'category_id' => $category, 'name' => 'Starter', 'description' => '', 'visible' => true, 'sort' => 0,
        'price_tokens' => 500, 'memory' => 2048, 'swap' => 0, 'disk' => 10000, 'cpu' => 100, 'io' => 500,
        'databases' => 1, 'backups' => 1, 'allocations' => 0, 'egg_ids' => [$this->egg->id], 'node_ids' => null,
    ]);
    $this->period = ShopPeriod::query()->forceCreate(['name' => 'Monthly', 'days' => 30, 'price_modifier_percent' => 0, 'sort' => 0, 'enabled' => true]);

    $this->user = User::factory()->create();
    $this->app->make(TokenWallet::class)->credit($this->user, 2000, 'admin_adjust', 'Test balance');
});

function gatewayOrder(): array
{
    $test = pterodactylTestCase();

    return [
        'plan_id' => $test->plan->id,
        'period_id' => $test->period->id,
        'egg_id' => $test->egg->id,
        'location_id' => $test->location->id,
        'name' => 'Survival',
        'expected_total' => 500,
        'idempotency_key' => 'key-'.uniqid().str_repeat('a', 10),
    ];
}

test('the shop only picks an allocation the gateway forwards and returns the gateway address', function (): void {
    Allocation::factory()->count(6)->sequence(fn ($sequence): array => ['port' => 30000 + $sequence->index])->create(['node_id' => $this->node->id]);
    $forwarded = Allocation::factory()->create(['node_id' => $this->node->id, 'port' => 25570]);

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', gatewayOrder())->assertCreated()
        ->assertJsonPath('attributes.address', 'play.example.com:25570');

    expect(Server::query()->where('owner_id', $this->user->id)->sole()->allocation_id)->toBe($forwarded->id);
});

test('a gateway node without a free forwarded allocation is skipped and administrators are told', function (): void {
    Allocation::factory()->create(['node_id' => $this->node->id, 'port' => 30000]);
    $admin = User::factory()->create(['root_admin' => true]);

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', gatewayOrder())->assertStatus(409);

    expect(Server::query()->where('owner_id', $this->user->id)->exists())->toBeFalse()
        ->and(UserNotice::query()->where('user_id', $admin->id)->sole()->body)
        ->toContain('Gateway node')->toContain('25565-25600');
});
