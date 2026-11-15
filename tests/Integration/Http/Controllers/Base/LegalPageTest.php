<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Http\Controllers\Base\LegalPageTest;

use Pterodactyl\Enum\LegalDocument;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Legal\LegalSettings;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

uses(HttpTestCase::class);

test('every legal page is readable without signing in', function (LegalDocument $document): void {
    $this->get($document->path())
        ->assertOk()
        ->assertViewIs('templates.base.core')
        ->assertViewHas('legalPage', fn (array $page): bool => $page['document'] === $document->value
            && $page['title'] === $document->label()
            && $page['content'] === LegalSettings::defaultContent($document)
            && $page['version'] === 1
            && str_starts_with($page['updatedAt'], LegalSettings::DEFAULT_UPDATED_AT))
        ->assertSee('window.LegalPage', false);

    $this->assertGuest();
})->with(LegalDocument::cases());

test('a legal page escapes the document in the page bootstrap', function (): void {
    LegalSettings::save(LegalDocument::Terms, '</script><script>alert(1)</script>', '', reaccept: false);

    $html = (string) $this->get('/legal/terms')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)')
        // The tags arrive as JSON unicode escapes the browser never parses as markup.
        ->and($html)->toContain(mb_trim((string) json_encode('<script>alert(1)', JSON_HEX_TAG), '"'));
});

test('a signed-in user who still has to set up two-factor authentication can read the terms', function (): void {
    config()->set('pterodactyl.auth.2fa_required', 2);

    $this->actingAs(User::factory()->create())->get('/legal/privacy')->assertOk();
});

test('a document with an external URL redirects there', function (): void {
    LegalSettings::save(LegalDocument::Refunds, '', 'https://example.com/refunds', reaccept: false);

    $this->get('/legal/refunds')->assertRedirect('https://example.com/refunds');
    $this->get('/legal/terms')->assertOk();
});

test('unknown legal pages fall through to the panel', function (): void {
    $this->get('/legal/cookies')->assertOk()->assertViewMissing('legalPage');
});

test('the page bootstrap lists the documents and what a signed-in user has to accept', function (): void {
    $user = User::factory()->create();

    $this->get('/auth/login')->assertViewHas('siteConfiguration', fn (array $config): bool => $config['legal']['pending'] === []
        && $config['legal']['documents']['terms']['url'] === '/legal/terms'
        && $config['legal']['documents']['refunds']['version'] === 1);

    LegalSettings::save(LegalDocument::Privacy, '', 'https://example.com/privacy', reaccept: true);

    $this->actingAs($user)->get('/')->assertViewHas('siteConfiguration', fn (array $config): bool => $config['legal']['pending'] === ['privacy']
        && $config['legal']['documents']['privacy']['url'] === 'https://example.com/privacy'
        && $config['legal']['documents']['privacy']['external'] === true
        && $config['legal']['documents']['privacy']['version'] === 2);
});
