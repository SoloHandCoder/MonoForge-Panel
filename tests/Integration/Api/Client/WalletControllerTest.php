<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\WalletControllerTest;

use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

test("shows the user's balance and only their own history, paginated", function (): void {
    $user = User::factory()->create();
    $wallet = resolve(TokenWallet::class);
    foreach (range(1, 3) as $i) {
        $wallet->credit($user, 100, TokenTransaction::TYPE_PURCHASE, "Bought tokens #{$i}");
    }

    $wallet->credit(User::factory()->create(), 999, TokenTransaction::TYPE_PURCHASE, 'Someone else');

    $response = $this->actingAs($user)->getJson('/api/client/account/wallet?per_page=2')->assertOk();

    $response->assertJsonPath('meta.balance', 300)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.attributes.description', 'Bought tokens #3')
        ->assertJsonPath('meta.pagination.total', 3);
});

test('a new account has an empty wallet', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/client/account/wallet')
        ->assertOk()
        ->assertJsonPath('meta.balance', 0)
        ->assertJsonCount(0, 'data');
});
