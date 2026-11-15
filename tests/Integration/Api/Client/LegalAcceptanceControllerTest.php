<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\LegalAcceptanceControllerTest;

use Pterodactyl\Enum\LegalDocument;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Legal\LegalSettings;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

test('users without a record count as having accepted the first version', function (): void {
    $user = User::factory()->create();

    expect(LegalSettings::pendingFor($user))->toBe([]);

    LegalSettings::save(LegalDocument::Terms, '# New terms', '', reaccept: true);
    expect(LegalSettings::pendingFor($user))->toBe([LegalDocument::Terms]);

    // The refund policy is never accepted on its own.
    LegalSettings::save(LegalDocument::Refunds, '# New refunds', '', reaccept: true);
    expect(LegalSettings::pendingFor($user))->toBe([LegalDocument::Terms]);
});

test('accepting records the current versions and clears the prompt', function (): void {
    $user = User::factory()->create(['terms_accepted_version' => 1, 'privacy_accepted_version' => 1]);
    LegalSettings::save(LegalDocument::Terms, '# New terms', '', reaccept: true);
    LegalSettings::save(LegalDocument::Privacy, '# New privacy', '', reaccept: true);
    LegalSettings::save(LegalDocument::Privacy, '# Newer privacy', '', reaccept: true);

    $this->actingAs($user)
        ->postJson('/api/client/account/legal/accept', ['terms_version' => 2, 'privacy_version' => 3])
        ->assertOk()
        ->assertJsonPath('terms_accepted_version', 2)
        ->assertJsonPath('privacy_accepted_version', 3);

    $user->refresh();
    expect($user->terms_accepted_version)->toBe(2)
        ->and($user->privacy_accepted_version)->toBe(3)
        ->and($user->terms_accepted_at?->isToday())->toBeTrue()
        ->and(LegalSettings::pendingFor($user))->toBe([]);
});

test('a document published while the prompt was open is not accepted unseen', function (): void {
    $user = User::factory()->create();
    LegalSettings::save(LegalDocument::Terms, '# New terms', '', reaccept: true);
    LegalSettings::save(LegalDocument::Terms, '# Newer terms', '', reaccept: true);

    $this->actingAs($user)
        ->postJson('/api/client/account/legal/accept', ['terms_version' => 2, 'privacy_version' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.detail', 'The Terms of Service changed while you were reading. Please review the new version.');

    expect($user->refresh()->terms_accepted_version)->toBeNull();
});

test('the versions are required', function (): void {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/client/account/legal/accept', [])
        ->assertUnprocessable();
});

test('accepting works before two-factor authentication is set up', function (): void {
    config()->set('pterodactyl.auth.2fa_required', 2);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/client/account/legal/accept', ['terms_version' => 1, 'privacy_version' => 1])
        ->assertOk();
});

test('API keys are not blocked by documents waiting to be accepted', function (): void {
    $user = User::factory()->create();
    LegalSettings::save(LegalDocument::Terms, '# New terms', '', reaccept: true);
    $key = ApiKey::factory()->create(['user_id' => $user->id, 'key_type' => ApiKey::TYPE_ACCOUNT]);

    $this->withHeader('Accept', 'application/vnd.pterodactyl.v1+json')
        ->withHeader('Authorization', 'Bearer '.$key->identifier.decrypt($key->token))
        ->getJson('/api/client/account')
        ->assertOk();

    expect(LegalSettings::pendingFor($user))->toBe([LegalDocument::Terms]);
});

test('guests cannot accept', function (): void {
    $this->postJson('/api/client/account/legal/accept', ['terms_version' => 1, 'privacy_version' => 1])->assertUnauthorized();
});
