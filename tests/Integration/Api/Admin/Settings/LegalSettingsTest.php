<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Settings\LegalSettingsTest;

use Pterodactyl\Enum\LegalDocument;
use Pterodactyl\Models\Setting;
use Pterodactyl\Services\Legal\LegalSettings;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

test('the documents start as the starter texts at version 1', function (): void {
    $response = $this->getJson('/api/admin/settings/legal')->assertOk()->assertJsonCount(3, 'data');

    $response->assertJsonPath('data.0.document', 'terms')
        ->assertJsonPath('data.0.title', 'Terms of Service')
        ->assertJsonPath('data.0.is_default', true)
        ->assertJsonPath('data.0.version', 1)
        ->assertJsonPath('data.0.external_url', '')
        ->assertJsonPath('data.0.public_url', '/legal/terms')
        ->assertJsonPath('data.1.document', 'privacy')
        ->assertJsonPath('data.2.document', 'refunds');

    foreach (LegalDocument::cases() as $index => $document) {
        expect($response->json("data.{$index}.content"))
            ->toStartWith('> **Template — review before relying on it; not legal advice.**')
            ->toBe(LegalSettings::defaultContent($document));
    }
});

test('saving stores the document and only raises the version when asked to', function (): void {
    $this->putJson('/api/admin/settings/legal/terms', ['content' => "# Our terms\n\nBe nice.", 'external_url' => '', 'reaccept' => false])
        ->assertOk()
        ->assertJsonPath('content', "# Our terms\n\nBe nice.")
        ->assertJsonPath('is_default', false)
        ->assertJsonPath('version', 1);

    expect(Setting::fetch('settings::monoforge:legal:terms:content'))->toBe("# Our terms\n\nBe nice.")
        ->and(Setting::fetch('settings::monoforge:legal:terms:version'))->toBe('1')
        ->and(LegalSettings::updatedAt(LegalDocument::Terms)->isToday())->toBeTrue();

    $this->putJson('/api/admin/settings/legal/terms', ['content' => "# Our terms\n\nBe very nice.", 'reaccept' => true])
        ->assertOk()
        ->assertJsonPath('version', 2);
    $this->putJson('/api/admin/settings/legal/terms', ['content' => "# Our terms\n\nBe very nice.", 'reaccept' => true])
        ->assertOk()
        ->assertJsonPath('version', 3);

    // The other documents are untouched.
    expect(LegalSettings::version(LegalDocument::Privacy))->toBe(1)
        ->and(LegalSettings::isDefault(LegalDocument::Privacy))->toBeTrue();
});

test('blank content goes back to the starter text', function (): void {
    LegalSettings::save(LegalDocument::Refunds, '# Custom', '', reaccept: false);

    $this->putJson('/api/admin/settings/legal/refunds', ['content' => '   ', 'reaccept' => false])
        ->assertOk()
        ->assertJsonPath('is_default', true)
        ->assertJsonPath('content', LegalSettings::defaultContent(LegalDocument::Refunds));
});

test('only https external URLs are accepted', function (string $url): void {
    $this->putJson('/api/admin/settings/legal/privacy', ['content' => '', 'external_url' => $url, 'reaccept' => false])
        ->assertUnprocessable();

    expect(LegalSettings::externalUrl(LegalDocument::Privacy))->toBe('');
})->with([
    'http' => 'http://example.com/privacy',
    'javascript' => 'javascript:alert(1)',
    'relative' => '/privacy',
    'not a url' => 'https://',
]);

test('an external URL is stored and becomes the link of the document', function (): void {
    $this->putJson('/api/admin/settings/legal/privacy', ['content' => '', 'external_url' => 'https://example.com/privacy', 'reaccept' => false])
        ->assertOk()
        ->assertJsonPath('external_url', 'https://example.com/privacy');

    expect(LegalSettings::link(LegalDocument::Privacy))->toBe('https://example.com/privacy')
        ->and(LegalSettings::link(LegalDocument::Terms))->toBe('/legal/terms');
});

test('documents too large for the settings table are refused', function (): void {
    $this->putJson('/api/admin/settings/legal/terms', ['content' => str_repeat('é', 30_001), 'reaccept' => false])
        ->assertUnprocessable();
});

test('unknown documents do not exist', function (): void {
    $this->putJson('/api/admin/settings/legal/cookies', ['content' => '', 'reaccept' => false])->assertNotFound();
});

test('non-admins cannot read or change the legal documents', function (): void {
    $this->actingAsNonAdmin();

    $this->getJson('/api/admin/settings/legal')->assertForbidden();
    $this->putJson('/api/admin/settings/legal/terms', ['content' => '', 'reaccept' => true])->assertForbidden();
    expect(LegalSettings::version(LegalDocument::Terms))->toBe(1);
});
