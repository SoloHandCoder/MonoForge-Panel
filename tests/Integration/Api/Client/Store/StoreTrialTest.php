<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Store\StoreTrialTest;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\TransientToken;
use Mockery;
use Pterodactyl\Contracts\Captcha\VerifiesCaptcha;
use Pterodactyl\Contracts\Servers\CreatesServers;
use Pterodactyl\Enum\OAuthProvider;
use Pterodactyl\Exceptions\Billing\SubscriptionRenewalException;
use Pterodactyl\Exceptions\Service\Billing\StorePurchaseException;
use Pterodactyl\Http\Controllers\Api\Client\Servers\SubuserController;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ShopCategory;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Billing\ShopTrial;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Objects\DeploymentObject;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserOAuthAccount;
use Pterodactyl\Services\Billing\CapacityService;
use Pterodactyl\Services\Billing\Quote;
use Pterodactyl\Services\Billing\SubscriptionService;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Services\Billing\TrialFingerprint;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonServer;

uses(ClientApiIntegrationTestCase::class);

/** Stands in for Wings: persists the server on the allocation the store picked, like CreateServer does. */
final class FakeTrialServerCreation implements CreatesServers
{
    /** Runs before the server is created (a concurrent request, say). */
    public ?Closure $before = null;

    public function create(array $input, ?DeploymentObject $deployment = null): Server
    {
        if ($this->before instanceof Closure) {
            ($this->before)();
        }

        $allocation = Allocation::query()->findOrFail($input['allocation_id']);
        $server = Server::factory()->create([
            ...Arr::only($input, ['external_id', 'name', 'owner_id', 'egg_id', 'image', 'startup', 'memory', 'swap', 'disk', 'io', 'cpu', 'database_limit', 'allocation_limit', 'backup_limit', 'allocation_id']),
            'node_id' => $allocation->node_id,
        ]);
        $allocation->forceFill(['server_id' => $server->id])->save();

        return $server;
    }
}

const PLAYER_IP = '203.0.113.7';

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-20 12:00:00');
    config([
        'monoforge.shop.enabled' => true,
        'monoforge.shop.limits' => ['memory_mb' => 0, 'disk_mb' => 0, 'cpu_percent' => 0],
        'monoforge.captcha.provider' => 'none',
    ]);
    // Removing a half-made server talks to Wings.
    $this->daemon = new FakeDaemonServer;
    $this->creation = new FakeTrialServerCreation;
    $this->app->instance(CreatesServers::class, $this->creation);

    $this->location = Location::factory()->create();
    $this->node = Node::factory()->create(['location_id' => $this->location->id, 'name' => 'Trial node', 'shop_enabled' => true]);
    Allocation::factory()->count(5)->create(['node_id' => $this->node->id]);

    $this->egg = Egg::query()->where('author', 'support@pterodactyl.io')->where('name', 'Bungeecord')->firstOrFail();
    $category = ShopCategory::query()->forceCreate(['name' => 'Minecraft', 'slug' => 'minecraft-'.uniqid(), 'sort' => 0, 'visible' => true])->id;
    $this->plan = ShopPlan::query()->forceCreate([
        'category_id' => $category, 'name' => 'Starter', 'description' => '', 'visible' => true, 'sort' => 0,
        'price_tokens' => 500, 'memory' => 2048, 'swap' => 0, 'disk' => 10000, 'cpu' => 100, 'io' => 500,
        'databases' => 1, 'backups' => 1, 'allocations' => 0, 'egg_ids' => [$this->egg->id], 'node_ids' => null,
    ]);
    $this->otherPlan = ShopPlan::query()->forceCreate([
        'category_id' => $category, 'name' => 'Big', 'description' => '', 'visible' => true, 'sort' => 1,
        'price_tokens' => 2000, 'memory' => 8192, 'swap' => 0, 'disk' => 40000, 'cpu' => 400, 'io' => 500,
        'databases' => 1, 'backups' => 1, 'allocations' => 0, 'egg_ids' => [$this->egg->id], 'node_ids' => null,
    ]);
    $this->period = ShopPeriod::query()->forceCreate(['name' => 'Monthly', 'days' => 30, 'price_modifier_percent' => 0, 'sort' => 0, 'enabled' => true]);

    config(['monoforge.shop.trials' => [
        'enabled' => true,
        'plan_ids' => (string) $this->plan->id,
        'hours' => 48,
        'max_concurrent' => 10,
        'require_verified' => true,
    ]]);

    $this->user = trialPlayer('1001');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** A new player with a linked Discord account. */
function trialPlayer(?string $discordId): User
{
    $user = User::factory()->create();
    if ($discordId !== null) {
        UserOAuthAccount::query()->create(['user_id' => $user->id, 'provider' => OAuthProvider::Discord, 'provider_user_id' => $discordId]);
    }

    return $user;
}

function trialOrder(array $overrides = []): array
{
    $test = pterodactylTestCase();

    return [
        'plan_id' => $test->plan->id,
        'egg_id' => $test->egg->id,
        'location_id' => $test->location->id,
        'name' => 'Trial world',
        'idempotency_key' => 'trial-'.uniqid().str_repeat('a', 10),
        ...$overrides,
    ];
}

function startTrial(User $user, array $overrides = [], string $ip = PLAYER_IP): TestResponse
{
    // A browser session of that player (what Sanctum gives a session-authenticated user).
    Auth::forgetGuards();
    $user->withAccessToken(new TransientToken);

    return pterodactylTestCase()->actingAs($user)->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/client/store/trial', trialOrder($overrides));
}

test('the catalog offers the trial to a new player and not after it was used', function (): void {
    $this->actingAs($this->user)->withServerVariables(['REMOTE_ADDR' => PLAYER_IP])->getJson('/api/client/store/catalog')
        ->assertOk()
        ->assertJsonPath('trial', ['enabled' => true, 'hours' => 48, 'plan_ids' => [$this->plan->id], 'eligible' => true, 'reason' => null]);

    startTrial($this->user)->assertCreated();

    $this->actingAs($this->user)->withServerVariables(['REMOTE_ADDR' => PLAYER_IP])->getJson('/api/client/store/catalog')
        ->assertOk()
        ->assertJsonPath('trial.eligible', false)
        ->assertJsonPath('trial.reason', 'You have already used your free trial.');

    config(['monoforge.shop.trials.enabled' => false]);
    $this->actingAs($this->user)->getJson('/api/client/store/catalog')->assertOk()->assertJsonPath('trial.enabled', false)->assertJsonPath('trial.plan_ids', []);
});

test('a new player starts one free trial server without being charged', function (): void {
    $order = trialOrder();
    $response = $this->actingAs($this->user)->withServerVariables(['REMOTE_ADDR' => PLAYER_IP])->postJson('/api/client/store/trial', $order)
        ->assertCreated()
        ->assertJsonPath('object', 'store_trial')
        ->assertJsonPath('attributes.hours', 48)
        ->assertJsonPath('attributes.replayed', false)
        ->assertJsonPath('attributes.ends_at', CarbonImmutable::now()->addHours(48)->toAtomString());

    $server = Server::query()->where('owner_id', $this->user->id)->sole();
    expect($response->json('attributes.server.uuid'))->toBe($server->uuid)
        ->and($server->memory)->toBe(2048)
        ->and($server->node_id)->toBe($this->node->id);

    $subscription = ServerSubscription::query()->where('server_id', $server->id)->sole();
    expect($subscription)
        ->status->toBe(ServerSubscription::STATUS_TRIAL)
        ->is_trial->toBeTrue()
        ->price_tokens->toBe(0)
        ->auto_renew->toBeFalse()
        ->next_renewal_at->toEqual(CarbonImmutable::now()->addHours(48));

    $trial = ShopTrial::query()->sole();
    expect($trial)
        ->user_id->toBe($this->user->id)
        ->server_id->toBe($server->id)
        ->plan_id->toBe($this->plan->id)
        ->subnet_hash->toBe(TrialFingerprint::subnet('203.0.113.200'))
        ->discord_hash->toBe(TrialFingerprint::identity('discord', '1001'))
        ->google_hash->toBeNull();

    // Nothing was charged and no ledger row was written.
    expect(TokenTransaction::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
    $this->assertActivityLogged('user:store.trial');

    // The same key returns the same server.
    $this->actingAs($this->user)->withServerVariables(['REMOTE_ADDR' => PLAYER_IP])->postJson('/api/client/store/trial', $order)
        ->assertOk()
        ->assertJsonPath('attributes.replayed', true)
        ->assertJsonPath('attributes.server.uuid', $server->uuid);
    expect(Server::query()->where('owner_id', $this->user->id)->count())->toBe(1);
});

test('one trial per account', function (): void {
    startTrial($this->user)->assertCreated();

    startTrial($this->user, [], '198.51.100.9')->assertForbidden()->assertJsonPath('errors.0.detail', 'You have already used your free trial.');
    expect(Server::query()->where('owner_id', $this->user->id)->count())->toBe(1);
});

test('an account that already bought a server gets no trial', function (): void {
    $this->app->make(TokenWallet::class)->credit($this->user, 500, 'admin_adjust', 'Test balance');
    $this->app->make(TokenWallet::class)->debit($this->user, 500, SubscriptionService::CHARGE_TYPE, 'Bought a server', 'store_purchase', 'store-x');

    startTrial($this->user)->assertForbidden()->assertJsonPath('errors.0.detail', 'Free trials are for new players, and your account already has a server from the store.');
    expect(ShopTrial::query()->exists())->toBeFalse();
});

test('a linked Discord or Google account is required when the admin asks for it', function (): void {
    $unverified = trialPlayer(null);

    startTrial($unverified)->assertForbidden()->assertJsonPath('errors.0.detail', 'Link a Discord or Google account on your account page to start a free trial.');

    config(['monoforge.shop.trials.require_verified' => false]);
    startTrial($unverified)->assertCreated();
});

test('a Discord account that was used for a trial cannot start another on an alt account', function (): void {
    startTrial($this->user)->assertCreated();

    // The alt links the same Discord id (after it was unlinked from the first account).
    UserOAuthAccount::query()->where('user_id', $this->user->id)->delete();
    $alt = trialPlayer('1001');

    startTrial($alt, [], '198.51.100.9')->assertForbidden()->assertJsonPath('errors.0.detail', 'Your linked Discord or Google account was already used for a free trial.');
});

test('one trial per /24 or /48 network every 30 days', function (): void {
    startTrial($this->user, [], '203.0.113.7')->assertCreated();

    // Another address in the same /24.
    startTrial(trialPlayer('2002'), [], '203.0.113.99')->assertForbidden()->assertJsonPath('errors.0.detail', 'A free trial was already started from your network recently.');
    // Another network is fine.
    startTrial(trialPlayer('2003'), [], '198.51.100.9')->assertCreated();

    // IPv6: the same /48.
    startTrial(trialPlayer('2004'), [], '2001:db8:1:aa::1')->assertCreated();
    startTrial(trialPlayer('2005'), [], '2001:db8:1:bb::2')->assertForbidden();

    // After 30 days the network may start a trial again.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(31));
    startTrial(trialPlayer('2006'), [], '203.0.113.50')->assertCreated();
});

test('the network an account signed up from counts too', function (): void {
    startTrial($this->user, [], '203.0.113.7')->assertCreated();

    // Signed up from the same network, now on another one.
    $alt = trialPlayer('3003');
    $alt->forceFill(['signup_subnet_hash' => TrialFingerprint::subnet('203.0.113.8')])->save();

    startTrial($alt, [], '198.51.100.9')->assertForbidden()->assertJsonPath('errors.0.detail', 'A free trial was already started from your network recently.');
});

test('networks are hashed per /24 for IPv4 and per /48 for IPv6', function (): void {
    expect(TrialFingerprint::subnet('203.0.113.7'))->toBe(TrialFingerprint::subnet('203.0.113.254'))
        ->not->toBe(TrialFingerprint::subnet('203.0.114.7'))
        ->and(TrialFingerprint::subnet('::ffff:203.0.113.9'))->toBe(TrialFingerprint::subnet('203.0.113.7'))
        ->and(TrialFingerprint::subnet('2001:db8:1:aa::1'))->toBe(TrialFingerprint::subnet('2001:db8:1:ffff::1'))
        ->and(TrialFingerprint::subnet('not an ip'))->toBeNull()
        ->and(TrialFingerprint::subnet('203.0.113.7'))->toHaveLength(64);
});

test('the panel-wide limit of running trials applies', function (): void {
    config(['monoforge.shop.trials.max_concurrent' => 1]);
    startTrial($this->user)->assertCreated();

    startTrial(trialPlayer('4004'), [], '198.51.100.9')->assertForbidden()->assertJsonPath('errors.0.detail', 'All free trial slots are taken right now. Please try again later.');

    config(['monoforge.shop.trials.max_concurrent' => 0]);
    startTrial(trialPlayer('4005'), [], '192.0.2.1')->assertForbidden();
});

test('only trialable plans of an open store, with trials on', function (): void {
    startTrial($this->user, ['plan_id' => $this->otherPlan->id])->assertUnprocessable()->assertJsonPath('errors.0.detail', 'That plan has no free trial.');

    config(['monoforge.shop.trials.enabled' => false]);
    startTrial($this->user)->assertForbidden()->assertJsonPath('errors.0.detail', 'Free trials are not available right now.');

    config(['monoforge.shop.trials.enabled' => true, 'monoforge.shop.enabled' => false]);
    startTrial($this->user)->assertForbidden();

    expect(Server::query()->where('owner_id', $this->user->id)->exists())->toBeFalse();
});

test('the capacity limits apply to trials, and running trials count against them', function (): void {
    config(['monoforge.shop.limits.memory_mb' => 3000]);

    startTrial($this->user)->assertCreated();
    expect($this->app->make(CapacityService::class)->used()['memory'])->toBe(2048);

    config(['monoforge.shop.trials.max_concurrent' => 10]);
    startTrial(trialPlayer('5005'), [], '198.51.100.9')->assertConflict()->assertJsonPath('errors.0.detail', StorePurchaseException::CAPACITY_MESSAGE);

    // The node's own shop limits as well.
    config(['monoforge.shop.limits.memory_mb' => 0]);
    $this->node->forceFill(['shop_limit_memory_mb' => 3000])->save();
    startTrial(trialPlayer('5006'), [], '192.0.2.1')->assertConflict();
});

test('the captcha is required when the panel uses one', function (): void {
    config(['monoforge.captcha.provider' => 'turnstile']);
    $verifier = Mockery::mock(VerifiesCaptcha::class);
    $verifier->shouldReceive('verify')->andReturn(['success' => false, 'hostname' => null, 'action' => null]);
    $this->app->instance(VerifiesCaptcha::class, $verifier);

    startTrial($this->user, ['g-recaptcha-response' => 'bad'])->assertStatus(400);
    expect(ShopTrial::query()->exists())->toBeFalse();
});

test('API keys cannot start a trial', function (): void {
    $key = ApiKey::factory()->create(['user_id' => $this->user->id, 'key_type' => ApiKey::TYPE_ACCOUNT]);
    Auth::forgetGuards();

    $this->withHeader('Accept', 'application/vnd.pterodactyl.v1+json')
        ->withHeader('Authorization', 'Bearer '.$key->identifier.decrypt($key->token))
        ->withServerVariables(['REMOTE_ADDR' => PLAYER_IP])
        ->postJson('/api/client/store/trial', trialOrder())
        ->assertForbidden();
    expect(ShopTrial::query()->exists())->toBeFalse();
});

test('a concurrent trial start that won the race refuses this one and removes its server', function (): void {
    // Another request of the same player records its trial while this one creates its server.
    $this->creation->before = function (): void {
        ShopTrial::query()->create(['user_id' => $this->user->id, 'hours' => 48, 'ends_at' => CarbonImmutable::now()->addHours(48)]);
    };

    startTrial($this->user)->assertForbidden()->assertJsonPath('errors.0.detail', 'You have already used your free trial.');

    expect(Server::query()->where('owner_id', $this->user->id)->exists())->toBeFalse()
        ->and(ServerSubscription::query()->where('user_id', $this->user->id)->exists())->toBeFalse()
        ->and(ShopTrial::query()->count())->toBe(1);
});

test('the subscription shows the trial with what keeping it costs, and renewing is refused', function (): void {
    startTrial($this->user)->assertCreated();
    $server = Server::query()->where('owner_id', $this->user->id)->sole();

    $this->actingAs($this->user)->getJson('/api/client/subscriptions')
        ->assertOk()
        ->assertJsonPath('data.0.attributes.status', 'trial')
        ->assertJsonPath('data.0.attributes.is_trial', true)
        ->assertJsonPath('data.0.attributes.can_renew_now', false)
        ->assertJsonPath('data.0.attributes.trial.ends_at', CarbonImmutable::now()->addHours(48)->toAtomString())
        ->assertJsonPath('data.0.attributes.trial.options', [['period_id' => $this->period->id, 'name' => 'Monthly', 'days' => 30, 'price_tokens' => 500]]);

    $this->actingAs($this->user)->postJson("/api/client/subscriptions/{$server->uuidShort}/renew")
        ->assertStatus(400)
        ->assertJsonPath('errors.0.detail', 'This is a free trial: pick a billing period with "Keep this server" to keep it.');
});

test('keeping the trial charges one period once and converts the subscription in place', function (): void {
    startTrial($this->user)->assertCreated();
    $server = Server::query()->where('owner_id', $this->user->id)->sole();
    $wallet = $this->app->make(TokenWallet::class);

    // Too few tokens: nothing changes.
    $this->actingAs($this->user)->postJson("/api/client/subscriptions/{$server->uuidShort}/keep", ['period_id' => $this->period->id])
        ->assertStatus(400)
        ->assertJsonPath('errors.0.detail', 'You need 500 tokens to keep this server. Top up your wallet and try again.');
    expect(ServerSubscription::query()->where('server_id', $server->id)->sole()->status)->toBe(ServerSubscription::STATUS_TRIAL);

    $wallet->credit($this->user, 1200, 'purchase', 'Top-up');
    // A price the player did not confirm is refused.
    $this->actingAs($this->user)->postJson("/api/client/subscriptions/{$server->uuidShort}/keep", ['period_id' => $this->period->id, 'expected_total' => 400])->assertStatus(400);

    $this->actingAs($this->user)->postJson("/api/client/subscriptions/{$server->uuidShort}/keep", ['period_id' => $this->period->id, 'expected_total' => 500])
        ->assertOk()
        ->assertJsonPath('attributes.status', 'active')
        ->assertJsonPath('attributes.is_trial', false)
        ->assertJsonPath('attributes.trial', null)
        ->assertJsonPath('attributes.price_tokens', 500);

    $subscription = ServerSubscription::query()->where('server_id', $server->id)->sole();
    expect($subscription)
        ->period_id->toBe($this->period->id)
        ->period_days->toBe(30)
        ->auto_renew->toBeTrue()
        ->next_renewal_at->toEqual(CarbonImmutable::now()->addDays(30))
        ->and($wallet->balance($this->user))->toBe(700)
        ->and(ShopTrial::query()->sole()->converted_at)->toEqual(CarbonImmutable::now());
    $this->assertActivityLogged('server:subscription.keep');

    // A second request charges nothing.
    $this->actingAs($this->user)->postJson("/api/client/subscriptions/{$server->uuidShort}/keep", ['period_id' => $this->period->id])
        ->assertStatus(400)
        ->assertJsonPath('errors.0.detail', 'This server is not a free trial any more.');
    expect($wallet->balance($this->user))->toBe(700);

    // A stale copy loaded while it was a trial cannot convert it again either.
    $stale = ServerSubscription::query()->findOrFail($subscription->id);
    $stale->forceFill(['is_trial' => true, 'status' => ServerSubscription::STATUS_TRIAL]);

    expect(fn () => $this->app->make(SubscriptionService::class)->convertTrial($stale, $this->period, new Quote(500, 1.0, 0, 500, 30, [], [])))
        ->toThrow(SubscriptionRenewalException::class);
    expect($wallet->balance($this->user))->toBe(700)
        ->and(TokenTransaction::query()->where('user_id', $this->user->id)->where('type', 'server_charge')->count())->toBe(1);
});

test('only the owner keeps a trial', function (): void {
    startTrial($this->user)->assertCreated();
    $server = Server::query()->where('owner_id', $this->user->id)->sole();

    $this->actingAs(trialPlayer('6006'))->postJson("/api/client/subscriptions/{$server->uuidShort}/keep", ['period_id' => $this->period->id])->assertNotFound();
});

test('at most the daily cap of trials is started per day', function (): void {
    config(['monoforge.shop.trials.daily_cap' => 1]);
    startTrial($this->user)->assertCreated();

    startTrial(trialPlayer('6006'), [], '198.51.100.60')
        ->assertForbidden()
        ->assertJsonPath('errors.0.detail', "Today's free trials are all taken. Please try again tomorrow.");

    // The next day the cap starts over.
    CarbonImmutable::setTestNow('2026-11-21 00:05:00');
    startTrial(trialPlayer('6007'), [], '192.0.2.60')->assertCreated();
});

test('a trial server cannot get subusers until it is kept', function (): void {
    startTrial($this->user)->assertCreated();
    $server = Server::query()->where('owner_id', $this->user->id)->sole();

    $this->actingAs($this->user)->postJson("/api/client/servers/{$server->uuidShort}/users", ['email' => 'friend@example.com', 'permissions' => ['control.console']])
        ->assertForbidden()
        ->assertJsonPath('errors.0.detail', SubuserController::TRIAL_REFUSAL);

    ServerSubscription::query()->where('server_id', $server->id)->update(['is_trial' => false]);
    $this->actingAs($this->user)->postJson("/api/client/servers/{$server->uuidShort}/users", ['email' => 'friend@example.com', 'permissions' => ['control.console']])
        ->assertJsonMissing(['detail' => SubuserController::TRIAL_REFUSAL]);
});
