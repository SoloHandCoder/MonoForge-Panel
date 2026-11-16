<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Settings\HelpSettingsTest;

use Pterodactyl\Models\HelpArticle;
use Pterodactyl\Services\Help\HelpArticles;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

/** @return array<string, mixed> */
function helpBody(array $overrides = []): array
{
    return [
        'title' => null,
        'category' => null,
        'summary' => null,
        'content' => null,
        'order' => null,
        'enabled' => true,
        ...$overrides,
    ];
}

test('the shipped articles are listed with the guides for administrators', function (): void {
    $response = $this->getJson('/api/admin/settings/help')->assertOk();
    $slugs = array_column($response->json('data'), 'slug');

    expect($slugs)->toContain('getting-started', 'joining-your-server', 'troubleshooting', 'operating-monoforge', 'setting-up-the-shop')
        ->and(count($slugs))->toBe(count(HelpArticles::defaults()));

    // Help center order: the players' articles first, the guides for administrators last.
    expect($slugs[0])->toBe('getting-started')
        ->and(array_slice($slugs, -4))->toBe(['operating-monoforge', 'setting-up-the-shop', 'game-gateway-vps', 'shared-minecraft-entrance']);

    $guide = collect($response->json('data'))->firstWhere('slug', 'operating-monoforge');
    expect($guide['audience'])->toBe('admin')
        ->and($guide['public_url'])->toBe('/panel/help');

    $joining = collect($response->json('data'))->firstWhere('slug', 'joining-your-server');
    expect($joining)->toMatchArray([
        'title' => 'Joining your server',
        'category' => 'Getting started',
        'audience' => 'public',
        'enabled' => true,
        'shipped' => true,
        'overridden' => false,
        'public_url' => '/help/joining-your-server',
    ])->and($joining['content'])->toBe(HelpArticles::defaults()['joining-your-server']['content']);
});

test('an override changes only the fields that differ from the starter text', function (): void {
    $default = HelpArticles::defaults()['the-console'];

    $this->putJson('/api/admin/settings/help/the-console', helpBody([
        'title' => 'Using the console',
        'category' => $default['category'],
        'summary' => '  ',
        'content' => $default['content'],
        'order' => $default['order'],
    ]))
        ->assertOk()
        ->assertJsonPath('title', 'Using the console')
        ->assertJsonPath('summary', $default['summary'])
        ->assertJsonPath('content', $default['content'])
        ->assertJsonPath('overridden', true)
        ->assertJsonPath('shipped', true);

    $row = HelpArticle::query()->where('slug', 'the-console')->sole();
    expect($row->title)->toBe('Using the console')
        ->and($row->category)->toBeNull()
        ->and($row->summary)->toBeNull()
        ->and($row->content)->toBeNull()
        ->and($row->sort_order)->toBeNull();

    $this->getJson('/api/public/help')->assertOk()
        ->assertJsonFragment(['slug' => 'the-console', 'title' => 'Using the console']);
});

test('reset brings back the starter text', function (): void {
    $this->putJson('/api/admin/settings/help/the-file-editor', helpBody(['content' => "# Changed\n\nOur own text.", 'enabled' => false]))
        ->assertOk()
        ->assertJsonPath('content', "# Changed\n\nOur own text.")
        ->assertJsonPath('enabled', false);

    $this->getJson('/api/public/help')->assertOk()->assertJsonMissing(['slug' => 'the-file-editor']);

    $this->deleteJson('/api/admin/settings/help/the-file-editor')->assertNoContent();

    expect(HelpArticle::query()->where('slug', 'the-file-editor')->exists())->toBeFalse();
    $this->getJson('/api/admin/settings/help')->assertOk();
    $entry = (new HelpArticles)->entry('the-file-editor');
    expect($entry)->not->toBeNull()
        ->and($entry['content'] ?? null)->toBe(HelpArticles::defaults()['the-file-editor']['content'])
        ->and($entry['enabled'] ?? null)->toBeTrue()
        ->and($entry['overridden'] ?? null)->toBeFalse();
});

test('an article can be added, needs a title and content, and is deleted by a reset', function (): void {
    $this->putJson('/api/admin/settings/help/discord-rules', helpBody(['title' => 'Discord rules']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', 'content');

    $this->putJson('/api/admin/settings/help/discord-rules', helpBody([
        'title' => ' Discord rules ',
        'content' => "# Discord rules\n\nBe kind.",
    ]))
        ->assertOk()
        ->assertJsonPath('title', 'Discord rules')
        ->assertJsonPath('category', HelpArticles::DEFAULT_CATEGORY)
        ->assertJsonPath('order', HelpArticles::DEFAULT_ORDER)
        ->assertJsonPath('shipped', false)
        ->assertJsonPath('public_url', '/help/discord-rules');

    $this->getJson('/api/public/help')->assertOk()->assertJsonFragment(['slug' => 'discord-rules', 'title' => 'Discord rules']);
    expect((new HelpArticles)->find('discord-rules'))->not->toBeNull();

    $this->deleteJson('/api/admin/settings/help/discord-rules')->assertNoContent();
    $this->deleteJson('/api/admin/settings/help/discord-rules')->assertNotFound();
    expect((new HelpArticles)->find('discord-rules'))->toBeNull();
});

test('the number of added articles is capped', function (): void {
    foreach (range(1, HelpArticles::MAX_CUSTOM) as $index) {
        HelpArticle::query()->create(['slug' => "extra-{$index}", 'title' => "Extra {$index}", 'content' => 'Text.', 'enabled' => true]);
    }

    $this->putJson('/api/admin/settings/help/one-too-many', helpBody(['title' => 'One too many', 'content' => 'Text.']))
        ->assertUnprocessable();

    // Changing an article that exists is still allowed.
    $this->putJson('/api/admin/settings/help/extra-1', helpBody(['title' => 'Extra one', 'content' => 'Text.']))->assertOk();
});

test('slugs and sizes are checked', function (): void {
    $this->putJson('/api/admin/settings/help/Not_A_Slug', helpBody(['title' => 'x', 'content' => 'x']))->assertNotFound();
    $this->putJson('/api/admin/settings/help/'.str_repeat('a', 65), helpBody(['title' => 'x', 'content' => 'x']))->assertNotFound();

    $this->putJson('/api/admin/settings/help/the-console', helpBody(['content' => str_repeat('a', HelpArticles::MAX_CONTENT_BYTES + 1)]))
        ->assertUnprocessable();
    $this->putJson('/api/admin/settings/help/the-console', helpBody(['title' => str_repeat('a', HelpArticles::MAX_TITLE + 1)]))
        ->assertUnprocessable();
    $this->putJson('/api/admin/settings/help/the-console', ['title' => 'No enabled flag'])
        ->assertUnprocessable();
});

test('markup in an edited article is stored as it was written, as text', function (): void {
    $markup = '<script>alert(1)</script><img src=x onerror=alert(2)>';

    $this->putJson('/api/admin/settings/help/support-tickets', helpBody(['summary' => $markup, 'content' => "# Tickets\n\n".$markup]))
        ->assertOk()
        ->assertJsonPath('summary', $markup);

    // The API hands it over as JSON text; the help center renders markdown without raw HTML.
    $this->getJson('/api/public/help')->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonFragment(['summary' => $markup]);
});

test('the guides for administrators are only for administrators', function (): void {
    $this->getJson('/api/admin/help')->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.slug', 'operating-monoforge')
        ->assertJsonPath('data.1.slug', 'setting-up-the-shop')
        ->assertJsonPath('data.2.slug', 'game-gateway-vps')
        ->assertJsonPath('data.3.slug', 'shared-minecraft-entrance');

    // Turned off, a guide leaves Admin > Help.
    $this->putJson('/api/admin/settings/help/setting-up-the-shop', helpBody(['enabled' => false]))->assertOk();
    $this->getJson('/api/admin/help')->assertOk()->assertJsonCount(3, 'data');

    $this->actingAsNonAdmin();
    $this->getJson('/api/admin/help')->assertForbidden();
    $this->getJson('/api/admin/settings/help')->assertForbidden();
    $this->putJson('/api/admin/settings/help/the-console', helpBody())->assertForbidden();
    $this->deleteJson('/api/admin/settings/help/the-console')->assertForbidden();
});
