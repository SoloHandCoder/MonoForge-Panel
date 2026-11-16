<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Http\Controllers\Base\ServerDiscoveryTest;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Data\ServerState;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Game;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Services\Query\QueryStatus;
use Pterodactyl\Services\Query\ServerQuery;
use Pterodactyl\Services\ServerPages\ServerDiscovery;
use Pterodactyl\Tests\Integration\Http\HttpTestCase;

use function pterodactylTestCase;

uses(HttpTestCase::class);

beforeEach(function (): void {
    Cache::flush();
    CarbonImmutable::setTestNow('2026-11-16 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * A listed page with a recent status.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $server
 */
function listedPage(string $slug, array $attributes = [], array $server = []): ServerPage
{
    $model = pterodactylTestCase()->createServerModel(['name' => ucfirst($slug).' Server', 'status' => null, ...$server]);

    return ServerPage::query()->forceCreate([
        'server_id' => $model->id,
        'slug' => $slug,
        'published' => true,
        'description' => 'A **friendly** server with [a link](https://example.com).',
        'last_status' => 'online',
        'last_players_online' => 1,
        'last_players_max' => 20,
        'status_checked_at' => CarbonImmutable::now()->subMinute(),
        ...$attributes,
    ])->setRelation('server', $model);
}

/** @return list<string> */
function listedSlugs(string $query = ''): array
{
    Cache::flush();
    $response = pterodactylTestCase()->getJson('/api/public/servers'.$query)->assertOk();

    return array_column($response->json('data'), 'slug');
}

test('the discovery page lists published, listed pages of installed servers only', function (): void {
    listedPage('listed');
    listedPage('unpublished', ['published' => false]);
    listedPage('opted-out', ['discoverable' => false]);
    listedPage('hidden', ['hidden_from_discovery' => true]);
    listedPage('suspended', server: ['status' => Server::STATUS_SUSPENDED]);
    listedPage('installing', server: ['status' => Server::STATUS_INSTALLING]);
    listedPage('failed', server: ['status' => Server::STATUS_INSTALL_FAILED]);

    expect(listedSlugs())->toBe(['listed']);
});

test('a card shows the page and never an ip address, node or allocation', function (): void {
    $page = listedPage('skyblock', ['tagline' => 'Islands and events', 'votes' => 7, 'banner_color' => '#FFD166']);
    $server = $page->server;
    ServerTunnel::query()->create(['server_id' => $server->id, 'address' => 'skyblock.joinmc.link', 'status' => TunnelStatus::Active]);

    $response = $this->getJson('/api/public/servers')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=30, public')
        ->assertJsonPath('meta', ['page' => 1, 'per_page' => 24, 'has_more' => false])
        ->assertJsonPath('data.0.slug', 'skyblock')
        ->assertJsonPath('data.0.url', '/s/skyblock')
        ->assertJsonPath('data.0.name', 'Skyblock Server')
        ->assertJsonPath('data.0.tagline', 'Islands and events')
        // Plain text: the markdown is gone and the link keeps only its words.
        ->assertJsonPath('data.0.description', 'A friendly server with a link.')
        ->assertJsonPath('data.0.game.slug', 'minecraft')
        ->assertJsonPath('data.0.status', 'online')
        ->assertJsonPath('data.0.players', ['online' => 1, 'max' => 20])
        ->assertJsonPath('data.0.votes', 7)
        ->assertJsonPath('data.0.address', 'skyblock.joinmc.link')
        ->assertJsonPath('data.0.theme.accent', '#ffd166')
        // A light accent gets dark text.
        ->assertJsonPath('data.0.theme.accent_text', '#000000')
        ->assertJsonPath('data.0.sections', ['players' => true, 'votes' => true, 'address' => true, 'description' => true]);

    $body = (string) $response->getContent();
    expect($body)->not->toContain($server->allocation->ip)
        ->not->toContain($server->node->fqdn)
        ->not->toContain($server->uuid)
        ->not->toContain(':'.$server->allocation->port);
    expect($response->headers->getCookies())->toBe([]);
});

test('only a live playit address is listed, and hidden sections are left out', function (): void {
    $page = listedPage('skyblock', ['show_players' => false, 'show_address' => false, 'show_description' => false]);
    ServerTunnel::query()->create(['server_id' => $page->server_id, 'address' => 'skyblock.joinmc.link', 'status' => TunnelStatus::Active]);
    $pending = listedPage('pending');
    ServerTunnel::query()->create(['server_id' => $pending->server_id, 'address' => 'pending.joinmc.link', 'status' => TunnelStatus::Pending]);

    $response = $this->getJson('/api/public/servers?sort=newest')->assertOk();

    expect($response->json('data.0.slug'))->toBe('pending')
        ->and($response->json('data.0.address'))->toBeNull()
        ->and($response->json('data.1.address'))->toBeNull()
        ->and($response->json('data.1.players'))->toBeNull()
        ->and($response->json('data.1.description'))->toBe('')
        ->and((string) $response->getContent())->not->toContain('skyblock.joinmc.link');
});

test('a status older than 15 minutes is unknown and does not count as players online', function (): void {
    listedPage('stale', ['last_players_online' => 50, 'status_checked_at' => CarbonImmutable::now()->subMinutes(16)]);
    listedPage('fresh', ['last_players_online' => 2]);

    $response = $this->getJson('/api/public/servers')->assertOk();

    expect(array_column($response->json('data'), 'slug'))->toBe(['fresh', 'stale'])
        ->and($response->json('data.1.status'))->toBe('unknown')
        ->and($response->json('data.1.players'))->toBeNull();
});

test('the list sorts by players online, votes or newest', function (): void {
    listedPage('busy', ['last_players_online' => 9, 'votes' => 1]);
    listedPage('loved', ['last_players_online' => 2, 'votes' => 50]);
    listedPage('quiet', ['last_players_online' => 0, 'votes' => 3]);
    // Players the owner hides do not count.
    listedPage('secret', ['last_players_online' => 99, 'votes' => 0, 'show_players' => false]);

    expect(listedSlugs())->toBe(['busy', 'loved', 'quiet', 'secret'])
        ->and(listedSlugs('?sort=votes'))->toBe(['loved', 'quiet', 'busy', 'secret'])
        ->and(listedSlugs('?sort=newest'))->toBe(['secret', 'quiet', 'loved', 'busy']);
});

test('the list filters by game from Admin > Games', function (): void {
    listedPage('minecraft');
    $game = Game::factory()->create(['slug' => 'valheim', 'name' => 'Valheim']);
    $egg = Egg::query()->where('name', '!=', 'Bungeecord')->firstOrFail();
    $egg->forceFill(['game_id' => $game->id])->save();
    listedPage('viking', server: ['egg_id' => $egg->id]);

    expect(listedSlugs('?game=valheim'))->toBe(['viking'])
        ->and(listedSlugs('?game=minecraft'))->toBe(['minecraft'])
        ->and(listedSlugs('?game=no-such-game'))->toBe([]);

    $response = $this->getJson('/api/public/servers?game=valheim')->assertOk()
        ->assertJsonPath('data.0.game', ['slug' => 'valheim', 'name' => 'Valheim', 'color' => $game->color]);
    expect(array_column($response->json('games'), 'slug'))->toContain('valheim', 'minecraft');
});

test('the list searches server names, with wildcards taken literally', function (): void {
    listedPage('skyblock', server: ['name' => 'Skyblock Island']);
    listedPage('survival', server: ['name' => 'Survival 100%']);

    expect(listedSlugs('?search=island'))->toBe(['skyblock'])
        ->and(listedSlugs('?search=100%25'))->toBe(['survival'])
        ->and(listedSlugs('?search=%25'))->toBe(['survival'])
        ->and(listedSlugs('?search=_'))->toBe([]);
});

test('the list is paginated, 24 at most a page', function (): void {
    foreach (['one', 'two', 'three'] as $slug) {
        listedPage("page-{$slug}");
    }

    $this->getJson('/api/public/servers?per_page=2&sort=newest')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta', ['page' => 1, 'per_page' => 2, 'has_more' => true]);
    $this->getJson('/api/public/servers?per_page=2&page=2&sort=newest')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'page-one')
        ->assertJsonPath('meta.has_more', false);
});

test('bad filters are refused', function (string $query): void {
    $this->getJson('/api/public/servers'.$query)->assertUnprocessable();
})->with([
    'too many a page' => ['?per_page=25'],
    'page zero' => ['?page=0'],
    'an unknown sort' => ['?sort=random'],
    'a long search' => ['?search='.str_repeat('a', ServerDiscovery::SEARCH_MAX + 1)],
    'a game with symbols' => ['?game=%3Cscript%3E'],
]);

test('the list is cached for 30 seconds and does not ask any game server', function (): void {
    $query = Mockery::mock(ServerQuery::class);
    $query->shouldNotReceive('status');

    $this->app->instance(ServerQuery::class, $query);
    $state = Mockery::mock(ReadsServerState::class);
    $state->shouldNotReceive('read');

    $this->app->instance(ReadsServerState::class, $state);

    $page = listedPage('skyblock');
    $this->getJson('/api/public/servers')->assertJsonCount(1, 'data');

    $page->forceFill(['published' => false])->save();
    $this->getJson('/api/public/servers')->assertJsonCount(1, 'data');

    Cache::flush();
    $this->getJson('/api/public/servers')->assertJsonCount(0, 'data');
});

test('the list is rate limited', function (): void {
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/public/servers')->assertOk();
    }

    $this->getJson('/api/public/servers')->assertTooManyRequests();
});

test('opening a page records its status for the discovery page', function (): void {
    $page = listedPage('skyblock', ['last_status' => null, 'last_players_online' => null, 'status_checked_at' => null]);
    $query = Mockery::mock(ServerQuery::class);
    $query->shouldReceive('status')->once()->andReturn(new QueryStatus(4, 30, 'Hi', '1.21.4', []));
    $this->app->instance(ServerQuery::class, $query);
    $updatedAt = $page->updated_at;

    $this->getJson('/api/public/servers/skyblock')->assertOk();

    $page->refresh();
    expect($page->last_status)->toBe('online')
        ->and($page->last_players_online)->toBe(4)
        ->and($page->last_players_max)->toBe(30)
        ->and($page->status_checked_at?->toDateTimeString())->toBe('2026-11-16 12:00:00')
        // The owner's last change stays what it was.
        ->and($page->updated_at?->toDateTimeString())->toBe($updatedAt?->toDateTimeString());
});

test('the scheduled refresh updates stale listed pages only, oldest first', function (): void {
    $stale = listedPage('stale', ['status_checked_at' => CarbonImmutable::now()->subMinutes(10), 'last_status' => 'online']);
    $never = listedPage('never', ['status_checked_at' => null, 'last_status' => null]);
    $fresh = listedPage('fresh', ['status_checked_at' => CarbonImmutable::now()->subMinute()]);
    listedPage('opted-out', ['discoverable' => false, 'status_checked_at' => null]);
    $this->app->instance(ReadsServerState::class, new readonly class implements ReadsServerState
    {
        public function read(Server $server): ServerState
        {
            return new ServerState('offline', false, 0, 0, 0, 0, 0, 0);
        }
    });

    Artisan::call('p:monoforge:refresh-discovery');

    expect($stale->refresh()->last_status)->toBe('offline')
        ->and($never->refresh()->last_status)->toBe('offline')
        ->and($fresh->refresh()->last_status)->toBe('online')
        ->and(ServerPage::query()->where('slug', 'opted-out')->value('last_status'))->toBe('online');
});

test('the discovery page carries its title and canonical address and may be indexed', function (): void {
    $root = mb_rtrim(config()->string('app.url'), '/');

    $this->get('/servers')
        ->assertOk()
        ->assertSee('<title>Discover game servers | MonoForge</title>', false)
        ->assertSee('<link rel="canonical" href="'.$root.'/servers">', false)
        ->assertDontSee('noindex', false);
});
