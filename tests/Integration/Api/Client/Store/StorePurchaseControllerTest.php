<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Store\StorePurchaseControllerTest;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Contracts\Servers\CreatesServers;
use Pterodactyl\Enum\LegalDocument;
use Pterodactyl\Exceptions\Service\Billing\StorePurchaseException;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Billing\ShopAddon;
use Pterodactyl\Models\Billing\ShopCategory;
use Pterodactyl\Models\Billing\ShopPeriod;
use Pterodactyl\Models\Billing\ShopPlan;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Objects\DeploymentObject;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;
use Pterodactyl\Services\Billing\CapacityService;
use Pterodactyl\Services\Billing\StorePurchaseService;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Services\Legal\LegalSettings;
use Pterodactyl\Services\Tunnels\TunnelService;
use Pterodactyl\Services\Tunnels\TunnelSettings;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use ReflectionClassConstant;
use RuntimeException;
use Throwable;

uses(ClientApiIntegrationTestCase::class);

/** Stands in for Wings: records what the store asked for and persists the server like CreateServer does. */
final class FakeServerCreation implements CreatesServers
{
    /** @var list<array<string, mixed>> */
    public array $inputs = [];

    public ?Throwable $failure = null;

    public function create(array $input, ?DeploymentObject $deployment = null): Server
    {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        $this->inputs[] = $input;
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
    config(['monoforge.shop.enabled' => true]);

    $this->creation = new FakeServerCreation;
    $this->app->instance(CreatesServers::class, $this->creation);

    $this->node = Node::factory()->create(['location_id' => Location::factory()->create()->id, 'memory' => 16384, 'disk' => 100000, 'shop_enabled' => true]);
    Allocation::factory()->count(2)->create(['node_id' => $this->node->id]);
    $this->egg = Egg::query()->where('author', 'support@pterodactyl.io')->where('name', 'Bungeecord')->firstOrFail();

    $category = ShopCategory::query()->forceCreate(['name' => 'Minecraft', 'slug' => 'minecraft-'.uniqid(), 'sort' => 0, 'visible' => true])->id;
    $this->plan = ShopPlan::query()->forceCreate([
        'category_id' => $category, 'name' => 'Starter', 'description' => '', 'visible' => true, 'sort' => 0,
        'price_tokens' => 500, 'memory' => 2048, 'swap' => 0, 'disk' => 10000, 'cpu' => 100, 'io' => 500,
        'databases' => 1, 'backups' => 1, 'allocations' => 0, 'egg_ids' => [$this->egg->id], 'node_ids' => null,
    ]);
    $this->period = ShopPeriod::query()->forceCreate(['name' => 'Monthly', 'days' => 30, 'price_modifier_percent' => 0, 'sort' => 0, 'enabled' => true]);

    $this->user = User::factory()->create();
    $this->app->make(TokenWallet::class)->credit($this->user, 1000, 'admin_adjust', 'Test balance');
});

function order(array $overrides = []): array
{
    $test = pterodactylTestCase();

    return [
        'plan_id' => $test->plan->id,
        'period_id' => $test->period->id,
        'egg_id' => $test->egg->id,
        'location_id' => $test->node->location_id,
        'name' => 'Survival',
        'expected_total' => 500,
        'idempotency_key' => 'key-'.str_repeat('a', 20),
        ...$overrides,
    ];
}

function balance(): int
{
    $test = pterodactylTestCase();

    return resolve(TokenWallet::class)->balance($test->user->refresh());
}

test('buys a server: charges the first period, creates it with the egg defaults and starts the subscription', function (): void {
    $response = $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertCreated();

    $server = Server::query()->where('owner_id', $this->user->id)->sole();
    $response->assertJsonPath('object', 'store_purchase')
        ->assertJsonPath('attributes.server.identifier', $server->uuidShort)
        ->assertJsonPath('attributes.tokens_charged', 500)
        ->assertJsonPath('attributes.period_days', 30)
        ->assertJsonPath('attributes.replayed', false);

    $input = $this->creation->inputs[0];
    expect(balance())->toBe(500)
        ->and($server->node_id)->toBe($this->node->id)
        ->and($input['memory'])->toBe(2048)
        ->and($input['backup_limit'])->toBe(1)
        ->and($input['image'])->toBe(collect($this->egg->docker_images)->first())
        ->and($input['startup'])->toBe($this->egg->startup)
        ->and($input['environment'])->toHaveKey('SERVER_JARFILE')
        ->and($input['external_id'])->toBe(StorePurchaseService::reference($this->user, order()['idempotency_key']))
        ->and(ServerSubscription::query()->where('server_id', $server->id)->value('price_tokens'))->toBe(500);
});

test('the subscription keeps the Terms of Service version the purchase accepted', function (): void {
    LegalSettings::save(LegalDocument::Terms, '# Terms', '', reaccept: true);

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertCreated();

    $server = Server::query()->where('owner_id', $this->user->id)->sole();
    expect(ServerSubscription::query()->where('server_id', $server->id)->sole()->terms_version)->toBe(2);
});

test('the same idempotency key never buys twice', function (): void {
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertCreated();
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertOk()
        ->assertJsonPath('attributes.replayed', true)
        ->assertJsonPath('attributes.tokens_charged', 500);

    expect(Server::query()->where('owner_id', $this->user->id)->count())->toBe(1)
        ->and(balance())->toBe(500);
});

test('add-ons raise the price and the resources', function (): void {
    ShopAddon::query()->forceCreate(['key' => 'backup', 'name' => 'Extra backup', 'description' => '', 'resource' => 'backups', 'amount' => 1, 'price_tokens' => 50, 'max_units' => 3, 'enabled' => true]);

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order(['addons' => ['backup' => 2], 'expected_total' => 600]))->assertCreated();

    expect($this->creation->inputs[0]['backup_limit'])->toBe(3)
        ->and(balance())->toBe(400);
});

test('a failure after the charge refunds it, and the failed key cannot be reused', function (): void {
    $this->creation->failure = new RuntimeException('Wings is down');

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())
        ->assertStatus(502)
        ->assertJsonPath('errors.0.detail', fn (string $detail): bool => str_contains($detail, 'your 500 tokens were refunded'));

    expect(balance())->toBe(1000)
        ->and(TokenTransaction::query()->where('reference_type', StorePurchaseService::REFERENCE_TYPE)->pluck('type')->sort()->values()->all())->toBe(['refund', 'server_charge'])
        ->and(Server::query()->where('owner_id', $this->user->id)->exists())->toBeFalse();

    $this->creation->failure = null;
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertStatus(409);
});

test('a short balance is refused before anything is created', function (): void {
    $this->app->make(TokenWallet::class)->debit($this->user, 600, 'admin_adjust', 'Test spend');

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertStatus(402);

    expect($this->creation->inputs)->toBe([])->and(balance())->toBe(400);
});

test('a sold-out shop refuses with the capacity message', function (): void {
    config(['monoforge.shop.limits.memory_mb' => 1024]);

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())
        ->assertStatus(409)
        ->assertJsonPath('errors.0.detail', StorePurchaseException::CAPACITY_MESSAGE);

    expect(balance())->toBe(1000);
});

test('a custom subdomain is checked against the Tunnels settings and kept on the tunnel', function (): void {
    Bus::fake();
    $this->node->forceFill(['playit_agent_id' => '00000000-0000-4000-8000-000000000001', 'playit_secret' => 'agent-secret'])->save();

    // Switched off: the name is refused, nothing is charged.
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order(['subdomain' => 'survival']))->assertStatus(422);

    TunnelSettings::save(true, 'monoforge.net', ['admin']);
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order(['subdomain' => 'admin', 'idempotency_key' => 'key-'.str_repeat('b', 20)]))->assertStatus(422);
    expect(balance())->toBe(1000);

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order(['subdomain' => 'Survival', 'idempotency_key' => 'key-'.str_repeat('c', 20)]))->assertCreated();

    $server = Server::query()->where('owner_id', $this->user->id)->sole();
    expect(ServerTunnel::query()->where('server_id', $server->id)->value('subdomain'))->toBe('survival')
        ->and(TunnelSettings::subdomainProblem('survival'))->toBe('That name is already taken.')
        ->and(balance())->toBe(500);
});

test('refuses a price the player did not confirm, an egg the plan does not offer and a closed shop', function (): void {
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order(['expected_total' => 499]))->assertStatus(409);
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order(['egg_id' => $this->egg->id + 1000]))->assertStatus(422);
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order(['name' => '', 'idempotency_key' => 'short']))->assertUnprocessable();

    // Past the three-a-minute purchase limit.
    $this->travel(61)->seconds();
    config(['monoforge.shop.enabled' => false]);
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertForbidden();

    expect($this->creation->inputs)->toBe([])->and(balance())->toBe(1000);
});

test('a node not available in the shop is refused, whether picked by location or by id', function (): void {
    $this->node->forceFill(['shop_enabled' => false])->save();

    // As for any location or node the plan cannot be placed on: there is no node to sell on.
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())
        ->assertStatus(409)
        ->assertJsonPath('errors.0.detail', StorePurchaseException::noNode()->getMessage());
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order([
        'location_id' => null,
        'node_id' => $this->node->id,
        'idempotency_key' => 'key-'.str_repeat('b', 20),
    ]))->assertStatus(409);

    expect($this->creation->inputs)->toBe([])
        ->and(Server::query()->where('owner_id', $this->user->id)->exists())->toBeFalse()
        ->and(balance())->toBe(1000);
});

test('a plan in a hidden category is not for sale', function (): void {
    $this->plan->category->forceFill(['visible' => false])->save();

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertStatus(422);

    expect($this->creation->inputs)->toBe([])->and(balance())->toBe(1000);
});

test("the capacity message is the owner's wording", function (): void {
    expect(StorePurchaseException::CAPACITY_MESSAGE)->toBe("The server currently can't provide servers, we hit our limit!");
});

test('purchases are serialised from the capacity check to the created server', function (): void {
    config(['monoforge.shop.capacity_lock_wait' => 0]);
    // Another purchase is between its capacity check and its server.
    $other = Cache::lock(StorePurchaseService::CAPACITY_LOCK, 60);
    expect($other->get())->toBeTrue();

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())
        ->assertStatus(409)
        ->assertJsonPath('errors.0.detail', 'The store is busy with other orders. Nothing was charged; please try again in a moment.');
    expect($this->creation->inputs)->toBe([])->and(balance())->toBe(1000);

    $other->release();
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertCreated();
    expect(balance())->toBe(500);
});

test('the capacity check sees the server the previous purchase created', function (): void {
    // Room for exactly one 2 GiB server.
    config(['monoforge.shop.limits.memory_mb' => 3000]);
    $this->app->make(TokenWallet::class)->credit($this->user, 1000, 'admin_adjust', 'More balance');

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertCreated();
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order(['idempotency_key' => 'key-'.str_repeat('z', 20)]))
        ->assertStatus(409)
        ->assertJsonPath('errors.0.detail', StorePurchaseException::CAPACITY_MESSAGE);

    expect(balance())->toBe(1500);
});

test('a subdomain on a location without playit is refused, not dropped after the charge', function (): void {
    TunnelSettings::save(true, 'monoforge.net', []);

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order(['subdomain' => 'survival']))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.detail', 'Custom subdomains are not available on that location. Leave the subdomain empty or pick another location.');

    expect($this->creation->inputs)->toBe([])->and(balance())->toBe(1000);
});

test('subdomains are lowercase ASCII, punycode and names containing a blocked word are refused, and a released name is free again', function (): void {
    Bus::fake();
    $this->node->forceFill(['playit_agent_id' => '00000000-0000-4000-8000-000000000001', 'playit_secret' => 'agent-secret'])->save();
    TunnelSettings::save(true, 'monoforge.net', ['Admin']);

    expect(TunnelSettings::subdomainProblem('xn--dmin-9ra'))->toBe('Use plain letters, digits and hyphens; encoded (xn--) names are not allowed.')
        ->and(TunnelSettings::subdomainProblem('my-ADMIN1'))->toBe('That name is reserved.')
        ->and(TunnelSettings::subdomainProblem('ädmin'))->not->toBeNull()
        ->and(TunnelSettings::subdomainProblem('bad_name'))->not->toBeNull()
        ->and(TunnelSettings::subdomainProblem(' Survival '))->toBeNull();

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order(['subdomain' => 'xn--srvival-xya']))->assertStatus(422);
    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order(['subdomain' => 'superadmin', 'idempotency_key' => 'key-'.str_repeat('d', 20)]))->assertStatus(422);
    expect(balance())->toBe(1000);

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order(['subdomain' => ' Survival ', 'idempotency_key' => 'key-'.str_repeat('e', 20)]))->assertCreated();
    $server = Server::query()->where('owner_id', $this->user->id)->sole();
    expect(TunnelSettings::subdomainProblem('survival'))->toBe('That name is already taken.');

    resolve(TunnelService::class)->release($server);
    expect(ServerTunnel::query()->where('server_id', $server->id)->value('subdomain'))->toBeNull()
        ->and(TunnelSettings::subdomainProblem('survival'))->toBeNull();
});

test('the database refuses a second tunnel with the same subdomain', function (): void {
    [$first, $second] = [$this->createServerModel(), $this->createServerModel()];
    ServerTunnel::query()->forceCreate(['server_id' => $first->id, 'subdomain' => 'survival']);

    expect(fn () => ServerTunnel::query()->forceCreate(['server_id' => $second->id, 'subdomain' => 'survival']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a server sold past the limits after all is kept and the root admins are told', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);
    config(['monoforge.shop.limits.memory_mb' => 1024]);
    // The capacity check under the lock says yes (a stale view, say), the server is created anyway.
    $this->app->instance(CapacityService::class, new class extends CapacityService
    {
        public function canProvision(array $resources): bool
        {
            return true;
        }
    });

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertCreated();

    expect(Server::query()->where('owner_id', $this->user->id)->exists())->toBeTrue()
        ->and(UserNotice::query()->where('user_id', $admin->id)->where('title', 'Store over capacity')->count())->toBe(1)
        ->and(UserNotice::query()->where('user_id', $this->user->id)->where('title', 'Store over capacity')->exists())->toBeFalse();
});

test('a server within the limits does not warn anyone', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);
    config(['monoforge.shop.limits.memory_mb' => 4096]);

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order())->assertCreated();

    expect(UserNotice::query()->where('user_id', $admin->id)->exists())->toBeFalse();
});

test('purchase attempts are limited to three a minute per player', function (): void {
    foreach (['a', 'b', 'c'] as $key) {
        $this->actingAs($this->user)->postJson('/api/client/store/purchase', order(['expected_total' => 1, 'idempotency_key' => 'key-'.str_repeat($key, 20)]))->assertStatus(409);
    }

    $this->actingAs($this->user)->postJson('/api/client/store/purchase', order(['idempotency_key' => 'key-'.str_repeat('d', 20)]))->assertTooManyRequests();
    expect(balance())->toBe(1000);

    // Another player is not affected.
    $other = User::factory()->create();
    $this->actingAs($other)->postJson('/api/client/store/purchase', order(['expected_total' => 1, 'idempotency_key' => 'key-'.str_repeat('e', 20)]))->assertStatus(409);
});

test('the store-wide lock outlives a slow purchase', function (): void {
    expect((new ReflectionClassConstant(StorePurchaseService::class, 'CAPACITY_LOCK_SECONDS'))->getValue())->toBe(180)
        ->and(config('monoforge.shop.capacity_lock_wait', 5))->toBeLessThanOrEqual(5);
});
