<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Database\ShopEnabledNodesMigrationTest;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Node;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

uses(IntegrationTestCase::class);
uses(DatabaseTransactions::class);

test('the migration keeps the shop open on nodes it already sells on and nowhere else', function (): void {
    $subscribed = $this->createServerModel();
    ServerSubscription::query()->create([
        'server_id' => $subscribed->id,
        'user_id' => $subscribed->owner_id,
        'period_days' => 30,
        'price_tokens' => 500,
        'addons' => [],
        'next_renewal_at' => now()->addDays(30),
    ]);
    $bought = $this->createServerModel(['external_id' => 'store-'.str_repeat('a', 40)]);
    $other = $this->createServerModel(['external_id' => 'billing-123']);
    $empty = Node::factory()->withLocation()->create();
    Node::query()->update(['shop_enabled' => false]);

    $migration = require database_path('migrations/2026_11_19_000020_add_shop_enabled_to_nodes.php');
    $migration->enableNodesWithSales();

    expect($subscribed->node->refresh()->shop_enabled)->toBeTrue()
        ->and($bought->node->refresh()->shop_enabled)->toBeTrue()
        ->and($other->node->refresh()->shop_enabled)->toBeFalse()
        ->and($empty->refresh()->shop_enabled)->toBeFalse();
});

test('new nodes are not in the shop until the owner opts them in', function (): void {
    expect(Node::factory()->withLocation()->create()->refresh()->shop_enabled)->toBeFalse();
});
