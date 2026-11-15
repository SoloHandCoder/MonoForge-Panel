<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\SubscriptionControllerTest;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonServer;

uses(ClientApiIntegrationTestCase::class);

beforeEach(function (): void {
    Notification::fake();
    $this->daemon = new FakeDaemonServer;
    $this->wallet = $this->app->make(TokenWallet::class);
    $this->app->instance(TokenWallet::class, $this->wallet);
});

/**
 * @return array{User, Server, ServerSubscription}
 */
function subscribed(object $test, array $attributes = []): array
{
    [$user, $server] = $test->generateTestAccount();
    $subscription = ServerSubscription::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'period_days' => 30,
        'price_tokens' => 500,
        'addons' => [['key' => 'backup', 'units' => 1]],
        'next_renewal_at' => CarbonImmutable::now()->addDays(20),
        ...$attributes,
    ]);

    return [$user, $server, $subscription];
}

test("lists only the user's own subscriptions", function (): void {
    [$user, $server] = subscribed($this);
    subscribed($this);

    $this->actingAs($user)->getJson('/api/client/subscriptions')
        ->assertOk()
        ->assertJsonPath('object', 'list')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.object', ServerSubscription::RESOURCE_NAME)
        ->assertJsonPath('data.0.attributes.server_identifier', $server->uuidShort)
        ->assertJsonPath('data.0.attributes.price_tokens', 500)
        ->assertJsonPath('data.0.attributes.can_renew_now', true);
});

test('auto-renew is changed by the payer only', function (): void {
    [$user, $server, $subscription] = subscribed($this);
    [$other] = $this->generateTestAccount();

    $this->actingAs($other)->patchJson("/api/client/subscriptions/{$server->uuidShort}", ['auto_renew' => false])->assertNotFound();
    $this->actingAs($user)->patchJson("/api/client/subscriptions/{$server->uuidShort}", ['auto_renew' => false])
        ->assertOk()
        ->assertJsonPath('attributes.auto_renew', false);

    expect($subscription->fresh()->auto_renew)->toBeFalse();
});

test('renew now works while the server is suspended and refuses a short balance', function (): void {
    [$user, $server, $subscription] = subscribed($this, [
        'status' => ServerSubscription::STATUS_SUSPENDED,
        'next_renewal_at' => CarbonImmutable::now()->subDay(),
        'suspended_at' => CarbonImmutable::now()->subDay(),
        // Billing made this suspension (the server was running before).
        'server_suspended_at' => CarbonImmutable::now()->subDay(),
        'suspended_by_billing' => true,
        'delete_at' => CarbonImmutable::now()->addDays(6),
    ]);
    $server->update(['status' => Server::STATUS_SUSPENDED]);

    $this->actingAs($user)->postJson("/api/client/subscriptions/{$server->uuidShort}/renew")
        ->assertStatus(400)
        ->assertJsonPath('errors.0.detail', 'You need 500 tokens to renew this server. Top up your wallet and try again.');

    $this->wallet->credit($user, 500, 'purchase', 'Top-up');
    $this->actingAs($user)->postJson("/api/client/subscriptions/{$server->uuidShort}/renew")
        ->assertOk()
        ->assertJsonPath('attributes.status', ServerSubscription::STATUS_ACTIVE)
        ->assertJsonPath('attributes.delete_at', null);

    expect($server->refresh()->isSuspended())->toBeFalse()
        ->and($this->wallet->balance($user))->toBe(0)
        ->and($subscription->fresh()->status)->toBe(ServerSubscription::STATUS_ACTIVE);
});
