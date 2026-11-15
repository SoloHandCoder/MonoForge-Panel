<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Users\TokenControllerTest;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

test("lists a user's ledger newest first with the balance", function (): void {
    $user = User::factory()->create();
    resolve(TokenWallet::class)->credit($user, 900, TokenTransaction::TYPE_PURCHASE, 'Bought tokens');
    resolve(TokenWallet::class)->debit($user, 200, TokenTransaction::TYPE_SERVER_CHARGE, 'Renewal');

    $this->getJson(route('api.admin.users.tokens', $user->id))
        ->assertOk()
        ->assertJsonPath('meta.balance', 700)
        ->assertJsonPath('data.0.object', TokenTransaction::RESOURCE_NAME)
        ->assertJsonPath('data.0.attributes.amount', -200)
        ->assertJsonPath('data.1.attributes.balance_after', 900);
});

test('adds and removes tokens with a reason', function (): void {
    $user = User::factory()->create();

    $this->postJson(route('api.admin.users.tokens.adjust', $user->id), ['amount' => 250, 'reason' => 'Giveaway winner'])
        ->assertStatus(Response::HTTP_CREATED)
        ->assertJsonPath('attributes.type', TokenTransaction::TYPE_ADMIN_ADJUST)
        ->assertJsonPath('attributes.description', 'Giveaway winner')
        ->assertJsonPath('meta.balance', 250);

    $this->postJson(route('api.admin.users.tokens.adjust', $user->id), ['amount' => -50, 'reason' => 'Correction'])
        ->assertStatus(Response::HTTP_CREATED)
        ->assertJsonPath('attributes.amount', -50)
        ->assertJsonPath('meta.balance', 200);

    expect(resolve(TokenWallet::class)->balance($user))->toBe(200);
    $this->assertActivityLogged('admin:user.adjust-tokens');
});

test('removing more than the balance needs allow_negative', function (): void {
    $user = User::factory()->create();

    $this->postJson(route('api.admin.users.tokens.adjust', $user->id), ['amount' => -10, 'reason' => 'Chargeback fee'])
        ->assertStatus(Response::HTTP_BAD_REQUEST);
    expect(resolve(TokenWallet::class)->balance($user))->toBe(0);

    $this->postJson(route('api.admin.users.tokens.adjust', $user->id), ['amount' => -10, 'reason' => 'Chargeback fee', 'allow_negative' => true])
        ->assertStatus(Response::HTTP_CREATED)
        ->assertJsonPath('meta.balance', -10);
});

test('rejects a zero, fractional or unexplained adjustment', function (array $body): void {
    $user = User::factory()->create();

    $this->postJson(route('api.admin.users.tokens.adjust', $user->id), $body)->assertUnprocessable();
    expect($user->tokenTransactions()->exists())->toBeFalse();
})->with([
    'zero' => [['amount' => 0, 'reason' => 'Nothing']],
    'fraction' => [['amount' => 1.5, 'reason' => 'Half']],
    'no reason' => [['amount' => 5]],
]);

test('non-admins cannot read or adjust tokens', function (): void {
    $user = User::factory()->create();
    $this->actingAsNonAdmin();

    $this->getJson(route('api.admin.users.tokens', $user->id))->assertForbidden();
    $this->postJson(route('api.admin.users.tokens.adjust', $user->id), ['amount' => 5, 'reason' => 'Sneaky'])->assertForbidden();
    expect(resolve(TokenWallet::class)->balance($user))->toBe(0);
});

test('an adjustment is capped at a million tokens per call', function (): void {
    $user = User::factory()->create();

    $this->postJson(route('api.admin.users.tokens.adjust', $user->id), ['amount' => 1_000_001, 'reason' => 'Too much'])->assertUnprocessable();
    $this->postJson(route('api.admin.users.tokens.adjust', $user->id), ['amount' => -1_000_001, 'reason' => 'Too much', 'allow_negative' => true])->assertUnprocessable();
    $this->postJson(route('api.admin.users.tokens.adjust', $user->id), ['amount' => 1_000_000, 'reason' => 'Big winner'])->assertCreated();

    expect(resolve(TokenWallet::class)->balance($user))->toBe(1_000_000);
});

test('an administrator cannot adjust their own wallet', function (): void {
    $admin = $this->getAdminUser();

    $this->postJson(route('api.admin.users.tokens.adjust', $admin->id), ['amount' => 500, 'reason' => 'Myself'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.detail', 'You cannot adjust your own token balance.');
    expect(resolve(TokenWallet::class)->balance($admin))->toBe(0);
});

test('the reason is stored on the ledger row and logged', function (): void {
    $user = User::factory()->create();

    $this->postJson(route('api.admin.users.tokens.adjust', $user->id), ['amount' => 42, 'reason' => '  Support goodwill  '])->assertCreated();

    $row = $user->tokenTransactions()->sole();
    expect($row->description)->toBe('Support goodwill')
        ->and($row->meta)->toBe(['admin_id' => $this->getAdminUser()->id]);
    $this->assertActivityLogged('admin:user.adjust-tokens');
});

test('an API key cannot adjust tokens, even a root administrator account key', function (): void {
    $user = User::factory()->create();
    $admin = User::factory()->create(['root_admin' => true]);
    $key = ApiKey::factory()->create(['user_id' => $admin->id, 'key_type' => ApiKey::TYPE_ACCOUNT]);
    Auth::forgetGuards();
    $this->withHeader('Accept', 'application/vnd.pterodactyl.v1+json')
        ->withHeader('Authorization', 'Bearer '.$key->identifier.decrypt($key->token));

    $this->postJson(route('api.admin.users.tokens.adjust', $user->id), ['amount' => 5, 'reason' => 'Through a key'])
        ->assertForbidden()
        ->assertJsonPath('errors.0.detail', 'This action can only be performed from an authenticated panel session, not with an API key.');
    expect(resolve(TokenWallet::class)->balance($user))->toBe(0);
});

test('only root administrators can adjust tokens, whatever permissions a role grants', function (): void {
    $staff = User::factory()->create(['root_admin' => false]);
    Gate::before(fn (User $user): ?bool => $user->id === $staff->id ? true : null);
    $this->actingAs($staff);
    $user = User::factory()->create();

    $this->postJson(route('api.admin.users.tokens.adjust', $user->id), ['amount' => 5, 'reason' => 'Support role'])->assertForbidden();
    expect(resolve(TokenWallet::class)->balance($user))->toBe(0);
});
