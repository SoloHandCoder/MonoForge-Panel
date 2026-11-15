<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Status\StatusPageTest;

use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Pterodactyl\Console\Commands\Status\CheckStatusCommand;
use Pterodactyl\Console\Scheduler;
use Pterodactyl\Models\Announcement;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Setting;
use Pterodactyl\Models\Status\StatusCheck;
use Pterodactyl\Models\Status\StatusDay;
use Pterodactyl\Services\Status\PublicStatusService;
use Pterodactyl\Services\Status\StatusMonitor;
use Pterodactyl\Services\Status\StatusPageSettings;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

uses(IntegrationTestCase::class, DatabaseTransactions::class);

/**
 * Two public nodes in one location, Wings answering only on the first, and a third node that is
 * not public (so hidden until an admin shows it).
 *
 * @return array{up: Node, down: Node, hidden: Node}
 */
function statusWorld(): array
{
    $location = Location::factory()->create(['short' => 'EU']);
    $up = Node::factory()->create(['location_id' => $location->id, 'name' => 'secret-alpha', 'fqdn' => 'alpha-node.example.test']);
    $down = Node::factory()->create(['location_id' => $location->id, 'name' => 'secret-bravo', 'fqdn' => 'bravo-node.example.test']);
    $hidden = Node::factory()->create(['location_id' => $location->id, 'name' => 'secret-charlie', 'fqdn' => 'charlie-node.example.test', 'public' => false]);

    Http::fake(function (HttpRequest $request): PromiseInterface {
        $host = parse_url($request->url(), PHP_URL_HOST);
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/api/public/status' => Http::response(['state' => 'operational']),
            $host === 'alpha-node.example.test' && $path === '/api/system' => Http::response(['cpu_count' => 4, 'version' => '1.0.0']),
            default => (Http::failedConnection())($request),
        };
    });

    return ['up' => $up, 'down' => $down, 'hidden' => $hidden];
}

beforeEach(function (): void {
    Cache::flush();
});

test('a check records the panel and each shown node, and sums the day', function (): void {
    $world = statusWorld();
    $monitor = $this->app->make(StatusMonitor::class);

    $monitor->run();
    $monitor->run();

    $targets = StatusCheck::query()->orderBy('id')->get()->groupBy('target');
    expect($targets->keys()->sort()->values()->all())->toBe(['node:'.$world['up']->id, 'node:'.$world['down']->id, 'panel'])
        ->and($targets['panel']->every(fn (StatusCheck $check): bool => $check->ok && $check->response_ms !== null))->toBeTrue()
        ->and($targets['node:'.$world['up']->id]->pluck('ok')->all())->toBe([true, true])
        ->and($targets['node:'.$world['down']->id]->pluck('ok')->all())->toBe([false, false]);

    $day = StatusDay::query()->where('target', 'node:'.$world['down']->id)->sole();
    expect($day->checks)->toBe(2)->and($day->successes)->toBe(0)->and($day->date->toDateString())->toBe(now()->toDateString());
    $panel = StatusDay::query()->where('target', 'panel')->sole();
    expect($panel->checks)->toBe(2)->and($panel->successes)->toBe(2)->and($panel->response_samples)->toBe(2);

    // Only Wings' system information is asked for, never the hidden node or any server.
    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'charlie-node') || str_contains($request->url(), '/api/servers'));
});

test('a recent insights reading is reused instead of asking Wings again', function (): void {
    $world = statusWorld();
    Cache::put("insights:node:{$world['down']->id}", ['online' => true, 'cpu_count' => 4, 'servers' => []], 60);

    $this->app->make(StatusMonitor::class)->run();

    expect(StatusCheck::query()->where('target', 'node:'.$world['down']->id)->value('ok'))->toBeTrue();
    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'bravo-node'));
});

test('the panel is down when it answers its own status endpoint with a server error, and has no response time when unreachable', function (): void {
    Http::fake(['*' => Http::response('', 502)]);
    $this->app->make(StatusMonitor::class)->run();
    expect(StatusCheck::query()->where('target', 'panel')->sole()->ok)->toBeFalse();

    StatusCheck::query()->delete();
    Http::fake(['*' => Http::failedConnection()]);
    $this->app->make(StatusMonitor::class)->run();
    $check = StatusCheck::query()->where('target', 'panel')->sole();
    expect($check->ok)->toBeTrue()->and($check->response_ms)->toBeNull();
});

test('nothing is checked while the status page is off', function (): void {
    statusWorld();
    Setting::put(StatusPageSettings::ENABLED_KEY, 'false');

    Artisan::call(CheckStatusCommand::class);

    expect(StatusCheck::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('the public status names nodes by display name only and works out the overall state', function (): void {
    $world = statusWorld();
    $this->app->make(StatusMonitor::class)->run();
    Announcement::factory()->create(['title' => 'Bravo maintenance', 'level' => 'danger']);
    Announcement::factory()->create(['title' => 'New plans', 'level' => 'info']);

    $response = $this->getJson('/api/public/status')->assertOk()
        ->assertJsonPath('state', 'partial_outage')
        ->assertJsonPath('panel.status', 'operational')
        ->assertJsonPath('panel.uptime', 100)
        ->assertJsonCount(PublicStatusService::DAYS, 'panel.days')
        ->assertJsonPath('panel.days.89.date', now()->toDateString())
        ->assertJsonPath('panel.days.89.uptime', 100)
        ->assertJsonPath('panel.days.0.uptime', null)
        ->assertJsonPath('nodes.0.name', 'EU Node 1')
        ->assertJsonPath('nodes.0.status', 'operational')
        ->assertJsonPath('nodes.1.name', 'EU Node 2')
        ->assertJsonPath('nodes.1.status', 'outage')
        ->assertJsonPath('nodes.1.uptime', 0)
        ->assertJsonCount(2, 'nodes')
        ->assertJsonCount(1, 'incidents')
        ->assertJsonPath('incidents.0.title', 'Bravo maintenance')
        ->assertJsonPath('incidents.0.level', 'danger');

    $body = (string) $response->getContent();
    foreach (['alpha-node', 'bravo-node', 'charlie-node', 'secret-', $world['up']->uuid, '"id":'.$world['up']->id] as $leak) {
        expect($body)->not->toContain($leak);
    }

    expect(array_keys($response->json('nodes.0')))->toBe(['name', 'status', 'uptime', 'days']);
});

test('every shown node down is a major outage, and stopped checks are unknown', function (): void {
    $world = statusWorld();
    Setting::put(StatusPageSettings::NODES_KEY, json_encode([$world['up']->id => ['visible' => false, 'name' => '']]));
    $this->app->make(StatusMonitor::class)->run();

    expect($this->app->make(PublicStatusService::class)->build()['state'])->toBe('major_outage');

    $this->travel(PublicStatusService::STALE_MINUTES + 1)->minutes();
    $status = $this->app->make(PublicStatusService::class)->build();
    expect($status['state'])->toBe('operational')
        ->and($status['panel']['status'])->toBe('unknown')
        ->and($status['nodes'][0]['status'])->toBe('unknown')
        ->and($status['checked_at'])->toBeNull();
});

test('the public status is cached for 30 seconds and missing while the page is off', function (): void {
    statusWorld();
    $this->getJson('/api/public/status')->assertOk()->assertJsonPath('panel.status', 'unknown');

    $this->app->make(StatusMonitor::class)->run();
    $this->getJson('/api/public/status')->assertOk()->assertJsonPath('panel.status', 'unknown');

    $this->travel(PublicStatusService::CACHE_SECONDS + 1)->seconds();
    $this->getJson('/api/public/status')->assertOk()->assertJsonPath('panel.status', 'operational');

    Setting::put(StatusPageSettings::ENABLED_KEY, 'false');
    $this->getJson('/api/public/status')->assertNotFound();
});

test('the status page is served to visitors who are not signed in', function (): void {
    $this->get('/status')->assertOk();
});

test('checks run every minute on one server without overlapping, and old rows are pruned', function (): void {
    $schedule = new Schedule;
    $this->app->make(Scheduler::class)($schedule);

    $check = collect($schedule->events())->first(fn (Event $event): bool => str_contains((string) $event->command, 'p:status:check'));
    expect($check)->not->toBeNull()
        ->and($check->expression)->toBe('* * * * *')
        ->and($check->withoutOverlapping)->toBeTrue()
        ->and($check->onOneServer)->toBeTrue();

    $old = CarbonImmutable::now()->subDays(StatusCheck::RETENTION_DAYS + 1);
    StatusCheck::query()->insert([
        ['target' => 'panel', 'ok' => true, 'response_ms' => 5, 'checked_at' => $old],
        ['target' => 'panel', 'ok' => true, 'response_ms' => 5, 'checked_at' => now()],
    ]);
    StatusDay::query()->insert([
        ['target' => 'panel', 'date' => $old->toDateString(), 'checks' => 1, 'successes' => 1, 'response_ms_total' => 5, 'response_samples' => 1],
        ['target' => 'panel', 'date' => now()->toDateString(), 'checks' => 1, 'successes' => 1, 'response_ms_total' => 5, 'response_samples' => 1],
    ]);

    Artisan::call('model:prune', ['--model' => [StatusCheck::class, StatusDay::class]]);

    expect(StatusCheck::query()->count())->toBe(1)->and(StatusDay::query()->count())->toBe(1);
});

test('the public status may be cached by browsers and the edge for 30 seconds, and is built by one request at a time', function (): void {
    statusWorld();

    $this->getJson('/api/public/status')->assertOk()->assertHeader('Cache-Control', 'max-age=30, public');

    // While another request holds the build lock, a request waits and then reads what that one stored.
    Cache::forget(PublicStatusService::CACHE_KEY);
    $other = Cache::lock(PublicStatusService::CACHE_KEY.':build', 30);
    expect($other->get())->toBeTrue();
    Sleep::fake();
    Sleep::whenFakingSleep(function () use ($other): void {
        if (! Cache::has(PublicStatusService::CACHE_KEY)) {
            Cache::put(PublicStatusService::CACHE_KEY, ['state' => 'built-elsewhere', 'checked_at' => null, 'panel' => [], 'nodes' => [], 'incidents' => []], 30);
            $other->release();
        }
    });

    expect($this->app->make(PublicStatusService::class)->payload()['state'])->toBe('built-elsewhere');
});
