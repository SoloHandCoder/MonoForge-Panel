<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Http\Controllers\Base\SitemapTest;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Enum\LegalDocument;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Services\Help\HelpArticles;
use Pterodactyl\Services\Legal\LegalSettings;
use Pterodactyl\Services\Seo\SeoFiles;
use Pterodactyl\Services\Status\StatusPageSettings;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

use function pterodactylTestCase;

uses(HttpTestCase::class);

beforeEach(function (): void {
    Cache::flush();
    config(['app.url' => 'https://panel.example.test']);
});

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $server
 */
function sitemapPage(string $slug, array $attributes = [], array $server = []): ServerPage
{
    $model = pterodactylTestCase()->createServerModel($server);

    return ServerPage::query()->forceCreate(['server_id' => $model->id, 'slug' => $slug, 'published' => true, ...$attributes]);
}

/** @return list<string> */
function sitemapLocations(string $xml): array
{
    preg_match_all('#<loc>([^<]+)</loc>#', $xml, $matches);

    return $matches[1];
}

test('the sitemap lists the public pages from the configured address', function (): void {
    $response = $this->get('http://attacker.example/sitemap.xml', ['Host' => 'attacker.example'])->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/xml');
    $xml = (string) $response->getContent();
    expect(simplexml_load_string($xml))->not->toBeFalse()
        ->and($xml)->not->toContain('attacker.example');

    $locations = sitemapLocations($xml);
    expect($locations)->toContain('https://panel.example.test/')
        ->toContain('https://panel.example.test/status')
        ->toContain('https://panel.example.test/help')
        ->toContain('https://panel.example.test/servers')
        ->toContain('https://panel.example.test/legal/terms')
        ->toContain('https://panel.example.test/legal/privacy')
        ->toContain('https://panel.example.test/legal/refunds');

    foreach (resolve(HelpArticles::class)->published('public') as $article) {
        expect($locations)->toContain('https://panel.example.test/help/'.$article['slug']);
    }

    // Never a page of the panel itself.
    foreach ($locations as $location) {
        expect($location)->not->toMatch('#^https://panel\.example\.test/(auth|account|admin|panel|server|api)(/|$)#');
    }
});

test('the sitemap is stateless: no cookie is set', function (): void {
    $response = $this->get('/sitemap.xml')->assertOk();

    expect($response->headers->getCookies())->toBeEmpty();
});

test('the sitemap lists published server pages, never hidden, unpublished, suspended or uninstalled ones', function (): void {
    $listed = sitemapPage('skyblock');
    sitemapPage('hidden', ['noindex' => true]);
    sitemapPage('draft', ['published' => false]);
    sitemapPage('banned', [], ['status' => Server::STATUS_SUSPENDED]);
    sitemapPage('fresh', [], ['status' => Server::STATUS_INSTALLING]);

    $xml = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

    expect(sitemapLocations($xml))->toContain('https://panel.example.test/s/skyblock')
        ->not->toContain('https://panel.example.test/s/hidden')
        ->not->toContain('https://panel.example.test/s/draft')
        ->not->toContain('https://panel.example.test/s/banned')
        ->not->toContain('https://panel.example.test/s/fresh')
        ->and($xml)->toContain('<loc>https://panel.example.test/s/skyblock</loc><lastmod>'.$listed->refresh()->updated_at?->toDateString().'</lastmod>');
});

test('the sitemap leaves out a turned off status page and legal documents kept on another site', function (): void {
    StatusPageSettings::save(false, []);
    LegalSettings::save(LegalDocument::Privacy, '', 'https://legal.example.test/privacy', reaccept: false);

    $locations = sitemapLocations((string) $this->get('/sitemap.xml')->assertOk()->getContent());

    expect($locations)->not->toContain('https://panel.example.test/status')
        ->not->toContain('https://panel.example.test/legal/privacy')
        ->not->toContain('https://legal.example.test/privacy')
        ->toContain('https://panel.example.test/legal/terms');
});

test('the sitemap escapes the panel address', function (): void {
    config(['app.url' => 'https://panel.example.test/a&b<c>']);

    $xml = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

    expect(simplexml_load_string($xml))->not->toBeFalse()
        ->and($xml)->toContain('https://panel.example.test/a&amp;b&lt;c&gt;/')
        ->not->toContain('<c>');
});

test('the sitemap is cached for an hour', function (): void {
    $this->get('/sitemap.xml')->assertOk();
    sitemapPage('later');

    expect(sitemapLocations((string) $this->get('/sitemap.xml')->getContent()))->not->toContain('https://panel.example.test/s/later');

    resolve(SeoFiles::class)->forget();
    expect(sitemapLocations((string) $this->get('/sitemap.xml')->getContent()))->toContain('https://panel.example.test/s/later');
});

test('the robots.txt shipped in public is the crawl rules', function (): void {
    $shipped = str_replace("\r\n", "\n", (string) file_get_contents(public_path('robots.txt')));

    // The panel may have written the sitemap line into it; the rules must still match.
    expect(preg_replace('/^Sitemap: .*\n/m', '', $shipped))->toBe(SeoFiles::ROBOTS_RULES);
});

test('robots.txt is written with the sitemap of the configured address', function (): void {
    $path = storage_path('framework/testing/robots-'.bin2hex(random_bytes(4)).'.txt');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    try {
        expect(resolve(SeoFiles::class)->writeRobots($path))->toBeTrue();
        $robots = (string) file_get_contents($path);

        expect($robots)->toStartWith(SeoFiles::ROBOTS_RULES)
            ->toEndWith("Sitemap: https://panel.example.test/sitemap.xml\n")
            ->and(preg_split('/\R/', $robots))
            ->toContain('Allow: /$')
            ->toContain('Allow: /status$')
            ->toContain('Allow: /legal/')
            ->toContain('Allow: /help/')
            ->toContain('Allow: /s/')
            ->toContain('Allow: /servers$')
            ->toContain('Disallow: /')
            ->not->toContain('Allow: /auth')
            ->not->toContain('Allow: /admin');

        // Unchanged content is left as it is.
        expect(resolve(SeoFiles::class)->writeRobots($path))->toBeTrue();
    } finally {
        unlink($path);
    }
});

test('robots.txt that cannot be written is reported, not thrown', function (): void {
    expect(resolve(SeoFiles::class)->writeRobots(storage_path('framework/testing/missing-'.bin2hex(random_bytes(4)).'/robots.txt')))->toBeFalse();
});

test('the robots command writes robots.txt', function (): void {
    $public = storage_path('framework/testing/public-'.bin2hex(random_bytes(4)));
    mkdir($public, 0755, true);
    $shipped = public_path();
    $this->app->usePublicPath($public);

    try {
        $this->artisan('p:monoforge:robots')->assertSuccessful();

        expect((string) file_get_contents($public.'/robots.txt'))->toEndWith("Sitemap: https://panel.example.test/sitemap.xml\n");
    } finally {
        $this->app->usePublicPath($shipped);
        unlink($public.'/robots.txt');
        rmdir($public);
    }
});

test('the status and legal pages carry their title and may be indexed', function (): void {
    $status = (string) $this->get('/status')->assertOk()->getContent();
    expect($status)->toContain('<title>Status | MonoForge</title>')
        ->toContain('<meta name="robots" content="index, follow">')
        ->toContain('<link rel="canonical" href="https://panel.example.test/status">');

    $terms = (string) $this->get('/legal/terms')->assertOk()->getContent();
    expect($terms)->toContain('<title>Terms of Service | MonoForge</title>')
        ->toContain('<meta name="robots" content="index, follow">')
        ->toContain('<link rel="canonical" href="https://panel.example.test/legal/terms">');
});

test('a turned off status page stays out of search', function (): void {
    StatusPageSettings::save(false, []);

    expect((string) $this->get('/status')->getContent())->toContain('<meta name="robots" content="noindex">')->not->toContain('Status | MonoForge');
});
