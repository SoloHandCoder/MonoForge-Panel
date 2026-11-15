<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Store\TokenPackCheckoutTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Enum\LegalDocument;
use Pterodactyl\Models\Billing\TokenPack;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\Tebex\TebexSettings;
use Pterodactyl\Services\Legal\LegalSettings;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

beforeEach(function (): void {
    resolve(TebexSettings::class)->save('abcd-public', null, null, []);
});

test('lists visible packs only while the store is set up', function (): void {
    TokenPack::query()->create(['tebex_package_id' => '2', 'name' => 'Big', 'tokens' => 1000, 'sort' => 2]);
    TokenPack::query()->create(['tebex_package_id' => '1', 'name' => 'Small', 'tokens' => 100, 'sort' => 1]);
    TokenPack::query()->create(['tebex_package_id' => '3', 'name' => 'Hidden', 'tokens' => 5, 'visible' => false]);

    $this->actingAs(User::factory()->create())->getJson('/api/client/store/tokens/packs')
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('data.*.name', ['Small', 'Big'])
        ->assertJsonMissingPath('data.0.tebex_package_id');

    resolve(TebexSettings::class)->save('', null, null, []);
    $this->getJson('/api/client/store/tokens/packs')->assertOk()->assertJsonPath('enabled', false)->assertJsonCount(0, 'data');
});

test('creates a basket for the pack and returns the Tebex checkout link', function (): void {
    $user = User::factory()->create();
    $pack = TokenPack::query()->create(['tebex_package_id' => '6012345', 'name' => '500 tokens', 'tokens' => 500]);

    Http::fake([
        'headless.tebex.io/api/accounts/abcd-public/baskets' => Http::response(['data' => ['ident' => 'bkt-1', 'links' => []]]),
        // The documented add-package path is missing, so the client falls back to the SDK's path.
        'headless.tebex.io/api/accounts/abcd-public/bkt-1/packages' => Http::response(['type' => 'error'], 404),
        'headless.tebex.io/api/baskets/bkt-1/packages' => Http::response(['data' => ['ident' => 'bkt-1', 'links' => ['checkout' => 'https://pay.tebex.io/bkt-1']]]),
    ]);

    $this->actingAs($user)->postJson('/api/client/store/tokens/checkout', ['pack_id' => $pack->id, 'tokens' => 999999])
        ->assertOk()
        ->assertExactJson(['url' => 'https://pay.tebex.io/bkt-1']);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/accounts/abcd-public/baskets')
        && $request['custom'] === ['user_id' => $user->id, 'pack_id' => $pack->id]
        && $request['complete_auto_redirect'] === true
        && is_string($request['ip_address'])
        && str_ends_with((string) $request['complete_url'], '/account/wallet?tebex=complete'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://headless.tebex.io/api/baskets/bkt-1/packages'
        && $request['package_id'] === '6012345'
        && $request['quantity'] === 1);
});

test('refuses hidden packs, a missing store and checkout links off Tebex', function (): void {
    $user = User::factory()->create();
    $hidden = TokenPack::query()->create(['tebex_package_id' => '1', 'name' => 'Hidden', 'tokens' => 5, 'visible' => false]);
    $pack = TokenPack::query()->create(['tebex_package_id' => '2', 'name' => 'Pack', 'tokens' => 5]);

    Http::fake([
        '*/baskets' => Http::response(['data' => ['ident' => 'bkt-2']]),
        '*/bkt-2/packages' => Http::response(['data' => ['links' => ['checkout' => 'https://evil.example.com/pay']]]),
    ]);

    $this->actingAs($user)->postJson('/api/client/store/tokens/checkout', ['pack_id' => $hidden->id])->assertBadRequest();
    $this->postJson('/api/client/store/tokens/checkout', ['pack_id' => $pack->id])->assertBadRequest();

    resolve(TebexSettings::class)->save('', null, null, []);
    $this->postJson('/api/client/store/tokens/checkout', ['pack_id' => $pack->id])->assertBadRequest();
});

test('a Tebex error is reported without leaking details', function (): void {
    $pack = TokenPack::query()->create(['tebex_package_id' => '2', 'name' => 'Pack', 'tokens' => 5]);
    Http::fake(['*' => Http::response(['detail' => 'Invalid webstore'], 422)]);

    $response = $this->actingAs(User::factory()->create())->postJson('/api/client/store/tokens/checkout', ['pack_id' => $pack->id])
        ->assertBadRequest();

    expect($response->getContent())->not->toContain('abcd-public')->not->toContain('Invalid webstore');
});

test('a user with updated terms to accept cannot start a checkout', function (): void {
    $user = User::factory()->create();
    $pack = TokenPack::query()->create(['tebex_package_id' => '6012345', 'name' => '500 tokens', 'tokens' => 500]);
    LegalSettings::save(LegalDocument::Terms, '# New terms', '', reaccept: true);
    Http::fake();

    $this->actingAs($user)->postJson('/api/client/store/tokens/checkout', ['pack_id' => $pack->id])
        ->assertConflict()
        ->assertJsonPath('errors.0.detail', 'Please accept the updated terms first.');

    Http::assertNothingSent();
});
