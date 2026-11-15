<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Servers\ServerSubscriptionControllerTest;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonServer;

uses(AdminApiIntegrationTestCase::class);

beforeEach(function (): void {
    Notification::fake();
    $this->daemon = new FakeDaemonServer;
    $this->server = $this->createServerModel(['status' => Server::STATUS_SUSPENDED]);
    $this->subscription = ServerSubscription::query()->create([
        'server_id' => $this->server->id,
        'user_id' => $this->server->owner_id,
        'period_days' => 30,
        'price_tokens' => 500,
        'status' => ServerSubscription::STATUS_SUSPENDED,
        'next_renewal_at' => CarbonImmutable::now()->subDay(),
        'suspended_at' => CarbonImmutable::now()->subDay(),
        'delete_at' => CarbonImmutable::now()->addDays(6),
    ]);
});

test('shows a server subscription, and 404 for a server without one', function (): void {
    $this->getJson(route('api.admin.servers.subscription', $this->server->id))
        ->assertOk()
        ->assertJsonPath('object', ServerSubscription::RESOURCE_NAME)
        ->assertJsonPath('attributes.status', ServerSubscription::STATUS_SUSPENDED)
        ->assertJsonPath('attributes.price_tokens', 500);

    $other = $this->createServerModel();
    $this->getJson(route('api.admin.servers.subscription', $other->id))->assertNotFound();
});

test('extend restores a suspended server and cancel stops billing', function (): void {
    $this->postJson(route('api.admin.servers.subscription.extend', $this->server->id), ['days' => 0])->assertUnprocessable();
    $this->postJson(route('api.admin.servers.subscription.extend', $this->server->id), ['days' => 7])
        ->assertOk()
        ->assertJsonPath('attributes.status', ServerSubscription::STATUS_ACTIVE)
        ->assertJsonPath('attributes.delete_at', null);
    expect($this->server->refresh()->isSuspended())->toBeFalse();

    $this->postJson(route('api.admin.servers.subscription.cancel', $this->server->id))
        ->assertOk()
        ->assertJsonPath('attributes.status', ServerSubscription::STATUS_CANCELLED)
        ->assertJsonPath('attributes.auto_renew', false);
});

test('non-admins cannot read or change subscriptions', function (): void {
    $this->actingAsNonAdmin();

    $this->getJson(route('api.admin.servers.subscription', $this->server->id))->assertForbidden();
    $this->postJson(route('api.admin.servers.subscription.cancel', $this->server->id))->assertForbidden();
});
