<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Http\Controllers\Base\PublicServerPageTest;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Pterodactyl\Contracts\Servers\DeletesServers;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Contracts\Servers\WakesServers;
use Pterodactyl\Data\ServerState;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Models\Share\ServerPageVote;
use Pterodactyl\Services\Query\QueryStatus;
use Pterodactyl\Services\Query\ServerQuery;
use Pterodactyl\Services\ServerPages\ServerPageVisitor;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;
use RuntimeException;

use function pterodactylTestCase;

uses(HttpTestCase::class);

beforeEach(function (): void {
    Cache::flush();
    // By default Wings reports the server stopped and the game does not answer.
    $this->app->instance(ReadsServerState::class, fakeState('offline'));
});

function fakeState(string $state): ReadsServerState
{
    return new readonly class($state) implements ReadsServerState
    {
        public function __construct(private string $state) {}

        public function read(Server $server): ServerState
        {
            return new ServerState($this->state, false, 0, 0, 0, 0, 0, 0);
        }
    };
}

/** A sleep service for the tests: which servers sleep, and which were woken. */
final class FakeSleep implements WakesServers
{
    /** @var list<int> */
    public array $woken = [];

    /** @param list<int> $sleeping */
    public function __construct(public array $sleeping = []) {}

    public function wake(Server $server): bool
    {
        $this->woken[] = $server->id;
        $this->sleeping = array_values(array_diff($this->sleeping, [$server->id]));

        return true;
    }

    public function isSleeping(Server $server): bool
    {
        return in_array($server->id, $this->sleeping, true);
    }
}

function publishedPage(array $attributes = [], array $server = []): ServerPage
{
    $model = pterodactylTestCase()->createServerModel(['name' => 'Skyblock Island', ...$server]);

    return ServerPage::query()->forceCreate([
        'server_id' => $model->id,
        'slug' => 'skyblock',
        'published' => true,
        'description' => 'A **friendly** server',
        'banner_color' => '#8b5cf6',
        ...$attributes,
    ])->setRelation('server', $model);
}

function vote(string $slug = 'skyblock', string $ip = '203.0.113.7', ?string $cookie = null): TestResponse
{
    $test = pterodactylTestCase();
    $test->withServerVariables(['REMOTE_ADDR' => $ip]);
    // Cookies set on the test case add up, so "no cookie" is sent as one that is not a visitor token.
    $test->withCredentials()->withUnencryptedCookies([ServerPageVisitor::COOKIE => $cookie ?? 'none']);

    return $test->postJson("/api/public/servers/{$slug}/vote");
}

test('a published page shows its details and never an ip address or node name', function (): void {
    $page = publishedPage();
    $server = $page->server;

    $response = $this->getJson('/api/public/servers/skyblock')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=30, public')
        ->assertJsonPath('slug', 'skyblock')
        ->assertJsonPath('name', 'Skyblock Island')
        ->assertJsonPath('description', 'A **friendly** server')
        ->assertJsonPath('banner', ['color' => '#8b5cf6', 'image_url' => null])
        ->assertJsonPath('status', 'offline')
        ->assertJsonPath('players', null)
        ->assertJsonPath('address', null)
        ->assertJsonPath('join_url', null)
        ->assertJsonPath('votes', 0);

    $body = (string) $response->getContent();
    expect($body)->not->toContain($server->allocation->ip)
        ->not->toContain($server->node->fqdn)
        ->not->toContain($server->uuid)
        ->not->toContain((string) $server->allocation->port);
    expect($response->headers->getCookies())->toBe([]);
});

test('unpublished, unknown, suspended and installing pages are not found', function (): void {
    publishedPage(['published' => false]);
    $this->getJson('/api/public/servers/skyblock')->assertNotFound();
    $this->getJson('/api/public/servers/nothing-here')->assertNotFound();

    publishedPage(['slug' => 'suspended'], ['status' => Server::STATUS_SUSPENDED]);
    $this->getJson('/api/public/servers/suspended')->assertNotFound();

    publishedPage(['slug' => 'installing'], ['status' => Server::STATUS_INSTALLING]);
    $this->getJson('/api/public/servers/installing')->assertNotFound();
});

test('only a live playit tunnel address is shown', function (): void {
    $page = publishedPage();
    $tunnel = ServerTunnel::query()->create(['server_id' => $page->server_id, 'address' => 'skyblock.joinmc.link', 'status' => TunnelStatus::Pending]);

    $this->getJson('/api/public/servers/skyblock')->assertJsonPath('address', null);

    $tunnel->forceFill(['status' => TunnelStatus::Active])->save();
    Cache::flush();
    $this->getJson('/api/public/servers/skyblock')->assertJsonPath('address', 'skyblock.joinmc.link');
});

test('the game answer gives the players and version, and the payload is cached', function (): void {
    publishedPage();
    $query = Mockery::mock(ServerQuery::class);
    $query->shouldReceive('status')->once()->andReturn(new QueryStatus(3, 20, 'Hi', "Paper 1.21.4\u{00A7}r\n", ['Steve']));
    $this->app->instance(ServerQuery::class, $query);

    $this->getJson('/api/public/servers/skyblock')
        ->assertJsonPath('status', 'online')
        ->assertJsonPath('players', ['online' => 3, 'max' => 20])
        ->assertJsonPath('version', 'Paper 1.21.4')
        ->assertJsonMissingPath('motd');

    // A second visitor within 30 seconds is served from the cache: the game is not asked again.
    $this->getJson('/api/public/servers/skyblock')->assertJsonPath('status', 'online');
});

test('without a game answer the power state from wings is used', function (string $state, string $status): void {
    publishedPage();
    $this->app->instance(ReadsServerState::class, fakeState($state));

    $this->getJson('/api/public/servers/skyblock')->assertJsonPath('status', $status)->assertJsonPath('players', null);
})->with([
    'running' => ['running', 'online'],
    'starting' => ['starting', 'starting'],
    'stopping' => ['stopping', 'offline'],
]);

test('an unreachable node shows the status as unknown', function (): void {
    publishedPage();
    $state = Mockery::mock(ReadsServerState::class);
    $state->shouldReceive('read')->andThrow(new DaemonConnectionException(new RuntimeException('down')));
    $this->app->instance(ReadsServerState::class, $state);

    $this->getJson('/api/public/servers/skyblock')->assertOk()->assertJsonPath('status', 'unknown');
});

test('a sleeping server says so without asking the game', function (): void {
    $page = publishedPage();
    $this->app->instance(WakesServers::class, new FakeSleep([$page->server_id]));
    $query = Mockery::mock(ServerQuery::class);
    $query->shouldNotReceive('status');

    $this->app->instance(ServerQuery::class, $query);

    $this->getJson('/api/public/servers/skyblock')->assertJsonPath('status', 'sleeping');
});

test('a visitor votes once a day, known by address or cookie, and neither is stored', function (): void {
    $page = publishedPage();

    $first = vote()->assertOk()->assertJsonPath('counted', true)->assertJsonPath('votes', 1);
    $cookie = collect($first->headers->getCookies())->firstWhere(fn ($cookie): bool => $cookie->getName() === ServerPageVisitor::COOKIE);
    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getValue())->toMatch('/\A[a-f0-9]{32}\z/');
    $token = $cookie->getValue();

    // The same address with the cookie cleared, and the same cookie from another address.
    vote()->assertOk()->assertJsonPath('counted', false)->assertJsonPath('votes', 1);
    vote(ip: '198.51.100.20', cookie: $token)->assertOk()->assertJsonPath('counted', false);

    // Another visitor counts.
    vote(ip: '198.51.100.21')->assertJsonPath('counted', true)->assertJsonPath('votes', 2);

    $stored = ServerPageVote::query()->get();
    expect($stored)->toHaveCount(2);
    foreach ($stored as $row) {
        expect($row->address_hash)->toMatch('/\A[a-f0-9]{64}\z/')->and($row->cookie_hash)->toMatch('/\A[a-f0-9]{64}\z/');
    }

    $dump = json_encode(ServerPageVote::query()->get()->toArray()).json_encode($page->refresh()->toArray());
    expect($dump)->not->toContain('203.0.113.7')->not->toContain('198.51.100')->not->toContain($token);

    // The public page shows the new total at once.
    $this->getJson('/api/public/servers/skyblock')->assertJsonPath('votes', 2);

    // A day later the first visitor votes again.
    $this->travelTo(CarbonImmutable::now()->addHours(24)->addMinute());
    vote(cookie: $token)->assertJsonPath('counted', true)->assertJsonPath('votes', 3);
});

test('an ipv6 visitor counts by their /64', function (): void {
    publishedPage();

    vote(ip: '2001:db8:1:2::1')->assertJsonPath('counted', true);
    vote(ip: '2001:db8:1:2:ffff::9')->assertJsonPath('counted', false);
    vote(ip: '2001:db8:1:3::1')->assertJsonPath('counted', true);
});

test('votes on one page do not block another page', function (): void {
    publishedPage();
    publishedPage(['slug' => 'other-one']);

    vote()->assertJsonPath('counted', true);
    vote('other-one')->assertJsonPath('counted', true);
});

test('votes and wakes must be sent as json, so another site cannot post a form', function (): void {
    publishedPage();

    $this->post('/api/public/servers/skyblock/vote', [], ['Content-Type' => 'application/x-www-form-urlencoded'])->assertStatus(415);
    $this->post('/api/public/servers/skyblock/wake', [], ['Content-Type' => 'text/plain'])->assertStatus(415);
    expect(ServerPageVote::query()->count())->toBe(0);

    // The panel's own client marks its requests instead, which another site cannot do either.
    $this->post('/api/public/servers/skyblock/vote', [], ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertJsonPath('counted', true);
});

test('an unpublished page cannot be voted for or woken', function (): void {
    publishedPage(['published' => false]);

    vote()->assertNotFound();
    $this->postJson('/api/public/servers/skyblock/wake')->assertNotFound();
});

test('a server that is not sleeping is not woken', function (): void {
    publishedPage();
    $sleep = new FakeSleep;
    $this->app->instance(WakesServers::class, $sleep);

    $this->postJson('/api/public/servers/skyblock/wake')->assertOk()->assertExactJson(['woken' => false, 'sleeping' => false]);
    expect($sleep->woken)->toBe([]);
});

test('a sleeping server is woken at most once a minute', function (): void {
    $page = publishedPage();
    $sleep = new FakeSleep([$page->server_id]);
    $this->app->instance(WakesServers::class, $sleep);

    $this->postJson('/api/public/servers/skyblock/wake')->assertOk()->assertJsonPath('woken', true);

    // It fell asleep again at once: a second visitor within the minute is refused.
    $sleep->sleeping = [$page->server_id];
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.30'])->postJson('/api/public/servers/skyblock/wake')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('errors.0.code', 'TooManyRequestsHttpException');
    expect($sleep->woken)->toBe([$page->server_id]);

    $this->travel(61)->seconds();
    $this->postJson('/api/public/servers/skyblock/wake')->assertOk()->assertJsonPath('woken', true);
    expect($sleep->woken)->toHaveCount(2);
});

test('one visitor wakes at most five servers an hour', function (): void {
    $sleep = new FakeSleep;
    $this->app->instance(WakesServers::class, $sleep);
    foreach (range(1, 6) as $index) {
        $sleep->sleeping[] = publishedPage(['slug' => "server-{$index}"])->server_id;
    }

    foreach (range(1, 5) as $index) {
        $this->postJson("/api/public/servers/server-{$index}/wake")->assertOk()->assertJsonPath('woken', true);
    }

    $this->postJson('/api/public/servers/server-6/wake')->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.31'])->postJson('/api/public/servers/server-6/wake')->assertOk()->assertJsonPath('woken', true);
});

test('the page carries its own title, description and open graph tags', function (): void {
    publishedPage(['description' => "Come **build** with us <script>alert(1)</script>\n\n- [Discord](https://discord.gg/x)"]);
    $root = mb_rtrim(config()->string('app.url'), '/');

    $html = (string) $this->get('/s/skyblock')->assertOk()->assertViewHas('seo')->getContent();

    expect($html)->toContain('<title>Skyblock Island | MonoForge</title>')
        ->toContain('<meta name="robots" content="index, follow">')
        ->toContain('<meta property="og:title" content="Skyblock Island | MonoForge">')
        ->toContain('<meta property="og:url" content="'.$root.'/s/skyblock">')
        ->toContain('<meta name="theme-color" content="#8b5cf6"')
        ->toContain('<meta property="og:description" content="Come build with us alert(1) Discord">')
        ->not->toContain('<script>alert(1)')->not->toContain('&lt;script');
});

test('the owner can ask search engines not to list the page', function (): void {
    publishedPage(['noindex' => true]);

    $html = (string) $this->get('/s/skyblock')->assertOk()->getContent();

    expect($html)->toContain('<meta name="robots" content="noindex">')->toContain('og:title');
});

test('an unpublished page is a 404 that is not indexed', function (): void {
    publishedPage(['published' => false]);

    $html = (string) $this->get('/s/skyblock')->assertNotFound()->getContent();

    expect($html)->toContain('<meta name="robots" content="noindex">')->not->toContain('og:title')->not->toContain('Skyblock Island');
});

test('the banner image is served only for its published page', function (): void {
    Storage::fake('local');
    $name = str_repeat('a', 40).'.png';
    Storage::disk('local')->put('server-pages/'.$name, 'image-bytes');
    $page = publishedPage(['banner_image' => $name]);
    $root = mb_rtrim(config()->string('app.url'), '/');

    $this->get("/s/skyblock/banner/{$name}")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertCookieMissing('pterodactyl_session');

    expect((string) $this->get('/s/skyblock')->getContent())
        ->toContain('<meta property="og:image" content="'.$root.'/s/skyblock/banner/'.$name.'">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">');

    $this->get('/s/skyblock/banner/'.str_repeat('b', 40).'.png')->assertNotFound();

    $page->forceFill(['published' => false])->save();
    $this->get("/s/skyblock/banner/{$name}")->assertNotFound();
});

test('robots.txt lets crawlers reach the public server pages', function (): void {
    $rules = preg_split('/\R/', (string) file_get_contents(public_path('robots.txt')));

    expect($rules)->toContain('Allow: /s/');
});

test('old votes are pruned once they no longer block a vote', function (): void {
    $page = publishedPage();
    ServerPageVote::query()->create(['server_page_id' => $page->id, 'address_hash' => str_repeat('a', 64), 'cookie_hash' => str_repeat('b', 64), 'voted_at' => CarbonImmutable::now()->subHours(25)]);
    ServerPageVote::query()->create(['server_page_id' => $page->id, 'address_hash' => str_repeat('c', 64), 'cookie_hash' => str_repeat('d', 64), 'voted_at' => CarbonImmutable::now()->subHours(2)]);

    $this->artisan('model:prune', ['--model' => [ServerPageVote::class]])->assertSuccessful();

    expect(ServerPageVote::query()->pluck('address_hash')->all())->toBe([str_repeat('c', 64)]);
});

test('deleting the server deletes its page and banner', function (): void {
    Storage::fake('local');
    $name = str_repeat('e', 40).'.webp';
    Storage::disk('local')->put('server-pages/'.$name, 'image-bytes');
    $page = publishedPage(['banner_image' => $name]);

    Http::fake();
    $this->app->make(DeletesServers::class)->delete($page->server()->firstOrFail());

    expect(ServerPage::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing('server-pages/'.$name);
});
