<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Shop\ShopNodeControllerTest;

use Illuminate\Support\Facades\Gate;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

test('lists every node with its shop availability, location and shop servers', function (): void {
    $location = Location::factory()->create(['short' => 'de', 'long' => 'Frankfurt']);
    $node = Node::factory()->create(['location_id' => $location->id, 'name' => 'aaa-node', 'shop_enabled' => true]);
    $shop = $this->createServerModel(['node_id' => $node->id]);
    ServerSubscription::query()->create([
        'server_id' => $shop->id, 'user_id' => $shop->owner_id, 'period_days' => 30, 'price_tokens' => 500,
        'addons' => [], 'next_renewal_at' => now()->addDays(30),
    ]);
    $this->createServerModel(['node_id' => $node->id]);

    $this->getJson(route('api.admin.shop.nodes'))
        ->assertOk()
        ->assertJsonPath('data.0', [
            'id' => $node->id,
            'name' => 'aaa-node',
            'location' => ['id' => $location->id, 'short' => 'de', 'long' => 'Frankfurt'],
            'public' => true,
            'maintenance' => false,
            'shop_enabled' => true,
            'shop_servers' => 1,
        ]);
});

test('toggles a node in and out of the shop', function (): void {
    $node = Node::factory()->withLocation()->create();
    expect($node->refresh()->shop_enabled)->toBeFalse();

    $this->putJson(route('api.admin.shop.nodes.update', $node->id), ['shop_enabled' => true])
        ->assertOk()
        ->assertJsonPath('id', $node->id)
        ->assertJsonPath('shop_enabled', true)
        ->assertJsonPath('shop_servers', 0);
    expect($node->refresh()->shop_enabled)->toBeTrue();
    $this->assertActivityLogged('admin:shop.node.enable');

    $this->putJson(route('api.admin.shop.nodes.update', $node->id), ['shop_enabled' => false])->assertOk()->assertJsonPath('shop_enabled', false);
    expect($node->refresh()->shop_enabled)->toBeFalse();
    $this->assertActivityLogged('admin:shop.node.disable');

    $this->putJson(route('api.admin.shop.nodes.update', $node->id), [])->assertUnprocessable();
});

test('shop availability stays out of the node API', function (): void {
    $node = Node::factory()->withLocation()->create(['shop_enabled' => true]);

    $this->getJson(route('api.admin.nodes.view', $node->id))->assertOk()->assertJsonMissingPath('attributes.shop_enabled');
});

test('the toggle needs the shop update permission', function (): void {
    $node = Node::factory()->withLocation()->create();

    $user = $this->actingAsNonAdmin();
    $this->getJson(route('api.admin.shop.nodes'))->assertForbidden();
    $this->putJson(route('api.admin.shop.nodes.update', $node->id), ['shop_enabled' => true])->assertForbidden();

    // Reading the shop is not enough to change it.
    Gate::define(Permissions::AdminShopRead->value, fn (User $actor): bool => $actor->is($user));
    $this->putJson(route('api.admin.shop.nodes.update', $node->id), ['shop_enabled' => true])->assertForbidden();

    expect($node->refresh()->shop_enabled)->toBeFalse();
});
