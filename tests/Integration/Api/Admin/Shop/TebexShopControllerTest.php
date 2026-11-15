<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Shop\TebexShopControllerTest;

use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Models\Billing\TebexPayment;
use Pterodactyl\Models\Billing\TokenPack;
use Pterodactyl\Models\Setting;
use Pterodactyl\Services\Billing\Tebex\TebexSettings;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

test('secrets are write-only and stored encrypted', function (): void {
    $this->putJson(route('api.admin.shop.tebex.update'), [
        'public_token' => 'abcd-public',
        'private_key' => 'private-value',
        'webhook_secret' => 'secret-value',
        'webhook_ips' => [],
    ])->assertOk()
        ->assertJsonPath('private_key_set', true)
        ->assertJsonPath('webhook_secret_set', true)
        ->assertJsonPath('webhook_ips', TebexSettings::DEFAULT_WEBHOOK_IPS)
        ->assertJsonMissingPath('private_key')
        ->assertJsonMissingPath('webhook_secret');

    $stored = (string) Setting::fetch('settings::monoforge:tebex:webhook_secret');
    expect($stored)->not->toContain('secret-value')
        ->and(Crypt::decrypt($stored))->toBe('secret-value');

    // A blank secret keeps the stored one.
    $response = $this->putJson(route('api.admin.shop.tebex.update'), [
        'public_token' => 'abcd-public',
        'private_key' => '',
        'webhook_secret' => null,
        'webhook_ips' => ['203.0.113.9'],
    ])->assertOk()->assertJsonPath('webhook_ips', ['203.0.113.9']);

    expect(resolve(TebexSettings::class)->webhookSecret())->toBe('secret-value')
        ->and(resolve(TebexSettings::class)->privateKey())->toBe('private-value')
        ->and($response->getContent())->not->toContain('secret-value')->not->toContain('private-value');

    $this->getJson(route('api.admin.shop.tebex'))->assertOk()
        ->assertJsonPath('checkout_enabled', true)
        ->assertJsonPath('webhook_url', mb_rtrim(config()->string('app.url'), '/').'/api/webhooks/tebex');
    $this->assertActivityLogged('admin:tebex.update');
});

test('rejects invalid webhook addresses', function (): void {
    $this->putJson(route('api.admin.shop.tebex.update'), ['public_token' => 'x', 'webhook_ips' => ['not-an-ip']])
        ->assertUnprocessable();
});

test('creates, lists, updates and deletes token packs', function (): void {
    $id = $this->postJson(route('api.admin.shop.token-packs.store'), [
        'tebex_package_id' => '6012345',
        'name' => '500 tokens',
        'tokens' => 500,
        'bonus_tokens' => 50,
    ])->assertCreated()->assertJsonPath('visible', true)->json('id');

    $this->postJson(route('api.admin.shop.token-packs.store'), ['tebex_package_id' => 'pack-ident', 'name' => 'x', 'tokens' => 0])
        ->assertUnprocessable();

    $this->getJson(route('api.admin.shop.token-packs'))->assertOk()->assertJsonPath('data.0.bonus_tokens', 50);

    $this->putJson(route('api.admin.shop.token-packs.update', ['tokenPack' => $id]), [
        'tebex_package_id' => '6012345',
        'name' => '600 tokens',
        'tokens' => 600,
        'visible' => false,
    ])->assertOk()->assertJsonPath('tokens', 600)->assertJsonPath('visible', false);

    $this->deleteJson(route('api.admin.shop.token-packs.delete', ['tokenPack' => $id]))->assertNoContent();
    expect(TokenPack::query()->count())->toBe(0);
    $this->assertActivityLogged('admin:token-pack.delete');
});

test('lists payments, optionally only flagged ones', function (): void {
    TebexPayment::query()->create(['transaction_id' => 'tbx-a', 'status' => 'completed', 'raw' => [['id' => '1', 'type' => 'payment.completed']]]);
    TebexPayment::query()->create(['transaction_id' => 'tbx-b', 'status' => 'refunded', 'raw' => [], 'flagged_at' => now()]);

    $this->getJson(route('api.admin.shop.tebex.payments'))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.events', ['payment.completed']);
    $this->getJson(route('api.admin.shop.tebex.payments', ['flagged' => 1]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.transaction_id', 'tbx-b');
});

test('non-admins cannot use the shop endpoints', function (): void {
    $this->actingAsNonAdmin();

    $this->getJson(route('api.admin.shop.tebex'))->assertForbidden();
    $this->putJson(route('api.admin.shop.tebex.update'), ['webhook_ips' => []])->assertForbidden();
    $this->getJson(route('api.admin.shop.token-packs'))->assertForbidden();
    $this->postJson(route('api.admin.shop.token-packs.store'), [])->assertForbidden();
    $this->getJson(route('api.admin.shop.tebex.payments'))->assertForbidden();
});
