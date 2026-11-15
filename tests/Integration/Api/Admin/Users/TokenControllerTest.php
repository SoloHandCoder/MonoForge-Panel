<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Users\TokenControllerTest;

use Illuminate\Http\Response;
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
