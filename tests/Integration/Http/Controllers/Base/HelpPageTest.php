<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Http\Controllers\Base\HelpPageTest;

use Pterodactyl\Models\HelpArticle;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Help\HelpArticles;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

uses(HttpTestCase::class);

test('every shipped article has a title, a category, a summary and markdown', function (): void {
    $defaults = HelpArticles::defaults();

    expect($defaults)->toHaveKeys([
        'getting-started', 'joining-your-server', 'tokens-and-wallet', 'buying-a-server', 'renewals-and-unpaid-servers',
        'the-console', 'the-file-editor', 'plugins-mods-and-modpacks', 'backups-and-schedules', 'ai-assistant-and-crash-doctor',
        'friends-and-sharing-access', 'public-server-page', 'support-tickets', 'account-security', 'troubleshooting',
        'operating-monoforge', 'setting-up-the-shop', 'game-gateway-vps',
    ]);

    foreach ($defaults as $slug => $article) {
        expect($article['title'])->not->toBe('', $slug)
            ->and($article['summary'])->not->toBe('', $slug)
            ->and(mb_strlen($article['summary']))->toBeLessThanOrEqual(HelpArticles::MAX_SUMMARY, $slug)
            ->and($article['content'])->toStartWith('# '.$article['title'], $slug)
            ->and($article['content'])->not->toContain('---', $slug)
            ->and($article['order'])->not->toBe(HelpArticles::DEFAULT_ORDER, $slug);

        // Links between articles point at articles that exist.
        preg_match_all('#\]\(/help/([a-z0-9-]+)\)#', $article['content'], $links);
        foreach ($links[1] as $target) {
            expect(array_key_exists($target, $defaults))->toBeTrue("{$slug} links to {$target}");
        }
    }

    expect(array_keys(array_filter($defaults, fn (array $article): bool => $article['audience'] === 'admin')))
        ->toBe(['game-gateway-vps', 'operating-monoforge', 'setting-up-the-shop']);
});

test('front matter is read with quotes, defaults and windows line endings', function (): void {
    $article = HelpArticles::parse('example', "---\r\ntitle: \"Quoted: title\"\r\norder: 7\r\naudience: admin\r\n---\r\n\r\n# Body\r\n");

    expect($article)->toBe([
        'slug' => 'example',
        'title' => 'Quoted: title',
        'category' => HelpArticles::DEFAULT_CATEGORY,
        'summary' => '',
        'content' => "# Body\n",
        'order' => 7,
        'audience' => 'admin',
    ]);

    expect(HelpArticles::parse('example', "# No front matter\n"))->toBeNull()
        ->and(HelpArticles::parse('example', "---\ncategory: x\n---\nNo title"))->toBeNull();
});

test('the help center is public, indexable and has a title and description', function (): void {
    $html = (string) $this->get('/help')->assertOk()->assertViewHas('seo')->getContent();

    expect($html)->toContain('<title>Help center | MonoForge</title>')
        ->toContain('<meta name="robots" content="index, follow">')
        ->toContain('<link rel="canonical" href="'.mb_rtrim(config()->string('app.url'), '/').'/help">')
        ->not->toContain('content="noindex"');

    $this->assertGuest();
});

test('every article page carries its own title and description', function (): void {
    $default = HelpArticles::defaults()['joining-your-server'];
    $html = (string) $this->get('/help/joining-your-server')->assertOk()->getContent();

    expect($html)->toContain('<title>Joining your server | MonoForge Help</title>')
        ->toContain('<meta name="description" content="'.e($default['summary']).'">')
        ->toContain('<link rel="canonical" href="'.mb_rtrim(config()->string('app.url'), '/').'/help/joining-your-server">')
        ->toContain('<meta name="robots" content="index, follow">');
});

test('an article without a summary is described by its first paragraph', function (): void {
    HelpArticle::query()->create(['slug' => 'house-rules', 'title' => 'House rules', 'content' => "# House rules\n\nBe **kind** to [everyone](/help).\n\nMore.", 'enabled' => true]);

    $this->get('/help/house-rules')->assertOk()
        ->assertSee('<meta name="description" content="Be kind to everyone.">', false);
});

test('an edited article cannot inject markup into the page head', function (): void {
    HelpArticle::query()->create([
        'slug' => 'support-tickets',
        'title' => 'Help "</title><script>alert(3)</script>',
        'summary' => '"><script>alert(1)</script><img src=x onerror=alert(2)>',
        'enabled' => true,
    ]);

    $html = (string) $this->get('/help/support-tickets')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(')
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->toContain('&lt;/title&gt;&lt;script&gt;alert(3)&lt;/script&gt; | MonoForge Help</title>')
        ->and($html)->toContain('<meta name="description" content="&quot;&gt;&lt;script&gt;alert(1)');
});

test('an article added by an administrator gets its own page', function (): void {
    HelpArticle::query()->create(['slug' => 'discord-rules', 'title' => 'Discord rules', 'summary' => 'Our rules.', 'content' => "# Discord rules\n\nBe kind.", 'enabled' => true]);

    $this->get('/help/discord-rules')->assertOk()->assertSee('<title>Discord rules | MonoForge Help</title>', false);
});

test('unknown, turned off and administrator articles are not found', function (): void {
    HelpArticle::query()->create(['slug' => 'the-console', 'enabled' => false]);

    $this->get('/help/no-such-article')->assertNotFound()->assertViewMissing('seo');
    $this->get('/help/the-console')->assertNotFound();
    $this->get('/help/operating-monoforge')->assertNotFound();

    $slugs = array_column($this->getJson('/api/public/help')->assertOk()->json('articles'), 'slug');
    expect($slugs)->not->toContain('the-console')
        ->not->toContain('operating-monoforge')
        ->not->toContain('setting-up-the-shop')
        ->toContain('getting-started');
});

test('the public help API lists the articles in help center order without signing in', function (): void {
    $response = $this->getJson('/api/public/help')->assertOk();
    $articles = $response->json('articles');

    expect($articles[0])->toBe([
        'slug' => 'getting-started',
        'title' => 'Getting started',
        'category' => 'Getting started',
        'summary' => HelpArticles::defaults()['getting-started']['summary'],
        'content' => HelpArticles::defaults()['getting-started']['content'],
    ])->and(array_column($articles, 'slug'))->toContain('troubleshooting');

    $this->assertGuest();
});

test('a signed-in user who still has to set up two-factor authentication can read the help center', function (): void {
    config()->set('pterodactyl.auth.2fa_required', 2);

    $this->actingAs(User::factory()->create())->get('/help/account-security')->assertOk();
    $this->actingAs(User::factory()->create())->get('/help')->assertOk();
});

test('robots.txt lets crawlers reach the help center', function (): void {
    $rules = preg_split('/\R/', (string) file_get_contents(public_path('robots.txt')));

    expect($rules)->toContain('Allow: /help$')->toContain('Allow: /help/');
});
