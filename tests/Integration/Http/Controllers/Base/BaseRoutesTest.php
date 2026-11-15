<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Http\Controllers\Base\BaseRoutesTest;

use Pterodactyl\Models\User;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

uses(HttpTestCase::class);
test('index renders the react shell for authenticated users', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/')->assertOk()->assertViewIs('templates.base.core');
    $this->actingAs($user)->get('/account')->assertOk()->assertViewIs('templates.base.core');
    $this->actingAs($user)->get('/server/some-uuid')->assertOk()->assertViewIs('templates.base.core');
});
test('guests also receive the react shell', function (): void {
    $this->get('/')->assertOk()->assertViewIs('templates.base.core');
});
test('locales are returned as json', function (): void {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->getJson('/locales/locale.json?locale=en&namespace=auth');
    $response->assertOk();

    expect($response->json())->toBeArray();
    expect($response->json())->not->toBeEmpty();
});
test('the homepage carries its title, description and open graph tags for guests and may be indexed', function (): void {
    $html = (string) $this->get('/')->assertOk()->assertViewHas('seo')->getContent();

    expect($html)->toContain('<title>MonoForge | Game server hosting for Minecraft and Terraria</title>')
        ->toContain('<meta name="robots" content="index, follow">')
        ->toContain('<meta name="description" content="Spin up a Minecraft Java or Terraria server')
        ->toContain('<meta property="og:title" content="MonoForge | Game server hosting')
        ->toContain('<meta property="og:image" content="'.url('/favicons/android-chrome-512x512.png').'">')
        ->toContain('<link rel="canonical" href="'.url('/').'">')
        ->not->toContain('content="noindex"');
});
test('the panel is never indexed: signed-in pages and every other path stay noindex', function (): void {
    $user = User::factory()->create();

    foreach ([$this->actingAs($user)->get('/'), $this->actingAs($user)->get('/account')] as $response) {
        $html = (string) $response->assertOk()->assertViewMissing('seo')->getContent();
        expect($html)->toContain('<meta name="robots" content="noindex">')->not->toContain('og:title');
    }

    auth()->logout();
    $html = (string) $this->get('/auth/login')->assertOk()->getContent();
    expect($html)->toContain('<meta name="robots" content="noindex">')->not->toContain('og:title');
});
test('robots.txt lets crawlers reach the homepage only', function (): void {
    $rules = preg_split('/\R/', (string) file_get_contents(public_path('robots.txt')));

    expect($rules)->toContain('Allow: /$')->toContain('Disallow: /')->not->toContain('Allow: /auth');
});
test('the page bootstrap tells the editor the largest file the panel serves for editing', function (): void {
    $this->get('/auth/login')->assertViewHas('siteConfiguration', fn (array $config): bool => $config['files']['maxEditSize'] === 4 * 1024 * 1024);

    config(['pterodactyl.files.max_edit_size' => 1024 * 1024]);
    $this->get('/auth/login')->assertViewHas('siteConfiguration', fn (array $config): bool => $config['files']['maxEditSize'] === 1024 * 1024);
});
