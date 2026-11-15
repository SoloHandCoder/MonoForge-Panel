<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Assistant\CrashDoctor\CrashDetectorTest;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Pterodactyl\Contracts\Servers\ReadsServerStates;
use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Data\ServerState;
use Pterodactyl\Data\ServerStateUnavailable;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerCrash;
use Pterodactyl\Services\Assistant\CrashDoctor\CrashDetector;
use Pterodactyl\Services\Assistant\CrashDoctor\CrashRecorder;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonPower;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonServer;

uses(IntegrationTestCase::class);
uses(DatabaseTransactions::class);

/** The Wings readings the detector gets, by server uuid. */
final class FakeStates implements ReadsServerStates
{
    /** @var array<string, ServerState|ServerStateUnavailable> */
    public array $states = [];

    public function read(iterable $servers): array
    {
        $result = [];
        foreach ($servers as $server) {
            if (isset($this->states[$server->uuid])) {
                $result[$server->uuid] = $this->states[$server->uuid];
            }
        }

        return $result;
    }

    public function set(Server $server, string $state, int $uptimeSeconds = 0): void
    {
        $this->states[$server->uuid] = ServerState::fromDaemon(['state' => $state, 'utilization' => ['uptime' => $uptimeSeconds * 1000]]);
    }
}

beforeEach(function (): void {
    config()->set('monoforge.ai.enabled', true);
    config()->set('monoforge.ai.server_enabled', true);
    config()->set('monoforge.ai.crash_doctor_enabled', true);
    CarbonImmutable::setTestNow('2026-11-15 12:00:00');

    $this->states = new FakeStates;
    $this->app->instance(ReadsServerStates::class, $this->states);
    $this->wings = new FakeDaemonServer;
    $this->wings->logs = [
        "\e[31m[12:00:01 ERROR]: Exception in server tick loop\e[0m",
        '[12:00:01 ERROR]: java.lang.OutOfMemoryError: Java heap space',
        'Steve joined from /198.51.100.23:51234 with password=hunter2secret',
    ];
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Runs the detector after $seconds more seconds. */
function minuteLater(int $seconds = 60): array
{
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($seconds));

    return resolve(CrashDetector::class)->run();
}

test('a running server that goes offline without a stop is recorded after the grace, with the console masked', function (): void {
    $server = $this->createServerModel();
    $this->states->set($server, 'running', 3600);
    expect(minuteLater(0))->toBe([]);

    $this->states->set($server, 'offline');
    expect(minuteLater())->toBe([])
        ->and(ServerCrash::query()->count())->toBe(0);

    // Still waiting for the console's activity.
    expect(minuteLater())->toBe([]);

    $crashes = minuteLater(100);
    expect($crashes)->toHaveCount(1);

    $crash = ServerCrash::query()->sole();
    expect($crash->server_id)->toBe($server->id)
        ->and($crash->kind)->toBe('crashed')
        ->and($crash->source)->toBe('scheduler')
        ->and($crash->status)->toBe('open')
        ->and($crash->console)->toContain('java.lang.OutOfMemoryError: Java heap space')
        ->and($crash->console)->not->toContain("\e[")
        ->and($crash->console)->not->toContain('198.51.100.23')
        ->and($crash->console)->not->toContain('hunter2secret');

    // Once recorded, the same suspect is not recorded again.
    expect(minuteLater())->toBe([]);
});

test('a server that was starting and went offline failed to start', function (): void {
    $server = $this->createServerModel();
    $this->states->set($server, 'starting');
    minuteLater(0);
    $this->states->set($server, 'offline');
    minuteLater();
    minuteLater(200);

    expect(ServerCrash::query()->sole()->kind)->toBe('start_failed');
});

test('a stop through the console, reported by Wings as activity, is not a crash', function (string $event, array $properties): void {
    $server = $this->createServerModel();
    $this->states->set($server, 'running', 3600);
    minuteLater(0);
    $this->states->set($server, 'offline');
    minuteLater();

    // Wings sends the console's power actions about a minute later.
    Activity::event($event)->subject($server)->property($properties)->log();

    minuteLater(200);
    expect(ServerCrash::query()->count())->toBe(0);
})->with([
    'stop' => ['server:power.stop', []],
    'kill' => ['server:power.kill', []],
    'restart' => ['server:power.restart', []],
    'stop command' => ['server:console.command', ['command' => 'stop']],
]);

test('another console command does not explain the stop', function (): void {
    $server = $this->createServerModel();
    $this->states->set($server, 'running', 3600);
    minuteLater(0);
    $this->states->set($server, 'offline');
    minuteLater();
    Activity::event('server:console.command')->subject($server)->property('command', 'say hello')->log();
    minuteLater(200);

    expect(ServerCrash::query()->count())->toBe(1);
});

test('a power change through the panel, a schedule or the CLI is not a crash', function (): void {
    $server = $this->createServerModel();
    $this->states->set($server, 'running', 3600);
    minuteLater(0);
    CrashRecorder::expectPowerChange($server, 'stop');
    $this->states->set($server, 'offline');
    minuteLater();
    minuteLater(200);

    expect(ServerCrash::query()->count())->toBe(0);
});

test('a server Wings brought back up after it went down is a crash', function (): void {
    $server = $this->createServerModel();
    $this->states->set($server, 'running', 3600);
    minuteLater(0);
    // Up for 10 seconds although a minute passed.
    $this->states->set($server, 'running', 10);
    minuteLater();
    minuteLater(200);

    expect(ServerCrash::query()->sole()->kind)->toBe('crashed');
});

test('a server that keeps running is not a crash', function (): void {
    $server = $this->createServerModel();
    $this->states->set($server, 'running', 3600);
    minuteLater(0);
    $this->states->set($server, 'running', 3660);
    minuteLater();
    minuteLater(200);

    expect(ServerCrash::query()->count())->toBe(0);
});

test('a start asked for through the panel that does not bring the server up failed', function (): void {
    $server = $this->createServerModel();
    $this->states->set($server, 'offline');
    minuteLater(0);
    CrashRecorder::expectPowerChange($server, 'start');
    minuteLater();
    minuteLater(200);

    expect(ServerCrash::query()->sole()->kind)->toBe('start_failed');
});

test('a start that worked is forgotten, so a later stop is not a failed start', function (): void {
    $server = $this->createServerModel();
    $this->states->set($server, 'offline');
    minuteLater(0);
    CrashRecorder::expectPowerChange($server, 'start');
    $this->states->set($server, 'running', 20);
    minuteLater(30);
    $this->states->set($server, 'offline');
    Activity::event('server:power.stop')->subject($server)->log();
    minuteLater();
    minuteLater(200);

    expect(ServerCrash::query()->count())->toBe(0);
});

test('most servers of a node going down at once is a node restart, not crashes', function (): void {
    $node = Node::query()->findOrFail($this->createServerModel()->node_id);
    $servers = collect(range(1, 3))->map(fn (): Server => $this->createServerModel(['node_id' => $node->id]));
    $servers->push(Server::query()->where('node_id', $node->id)->firstOrFail());

    foreach ($servers as $server) {
        $this->states->set($server, 'running', 3600);
    }

    minuteLater(0);
    foreach ($servers as $server) {
        $this->states->set($server, 'offline');
    }

    minuteLater();
    minuteLater(200);

    expect(ServerCrash::query()->count())->toBe(0);
});

test('an unreadable node leaves no baseline', function (): void {
    $server = $this->createServerModel();
    $this->states->set($server, 'running', 3600);
    minuteLater(0);
    unset($this->states->states[$server->uuid]);
    minuteLater();
    $this->states->set($server, 'offline');
    minuteLater();
    minuteLater(200);

    expect(ServerCrash::query()->count())->toBe(0);
});

test('suspended and installing servers are not watched', function (): void {
    $server = $this->createServerModel(['status' => Server::STATUS_SUSPENDED]);
    $this->states->set($server, 'running', 3600);
    minuteLater(0);
    $this->states->set($server, 'offline');
    minuteLater();
    minuteLater(200);

    expect(ServerCrash::query()->count())->toBe(0);
});

test('nothing is watched while the crash doctor or the assistant is off', function (string $key): void {
    config()->set($key, false);
    $server = $this->createServerModel();
    $this->states->set($server, 'running', 3600);
    minuteLater(0);
    $this->states->set($server, 'offline');
    minuteLater();
    minuteLater(200);

    expect(ServerCrash::query()->count())->toBe(0)
        ->and(cache()->get(CrashDetector::stateKey($server)))->toBeNull();
})->with(['monoforge.ai.crash_doctor_enabled', 'monoforge.ai.server_enabled', 'monoforge.ai.enabled']);

test('a second crash within ten minutes is the same incident', function (): void {
    $server = $this->createServerModel();
    $recorder = resolve(CrashRecorder::class);

    expect($recorder->record($server, 'crashed', 'console'))->toBeInstanceOf(ServerCrash::class)
        ->and($recorder->record($server, 'crashed', 'scheduler'))->toBeNull();

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(11));
    expect($recorder->record($server, 'start_failed', 'scheduler'))->toBeInstanceOf(ServerCrash::class);
});

test('the command runs the detector and is scheduled every minute', function (): void {
    $server = $this->createServerModel();
    $this->states->set($server, 'running', 3600);

    expect(Artisan::call('p:crash-doctor:detect'))->toBe(0)
        ->and(cache()->get(CrashDetector::stateKey($server))['state'])->toBe('running');

    Artisan::call('schedule:list');
    expect(Artisan::output())->toMatch('/\*\s+\*\s+\*\s+\*\s+\*\s+php artisan p:crash-doctor:detect/');
});

test('a power signal sent through the panel is remembered, and the stop command is recognised', function (): void {
    $server = $this->createServerModel();
    new FakeDaemonPower;

    resolve(SendsServerPower::class)->send($server, 'restart');
    expect(cache()->get(CrashRecorder::powerKey($server))['signal'])->toBe('restart')
        ->and(resolve(CrashRecorder::class)->stopRequestedSince($server, CarbonImmutable::now()->subMinute()))->toBeTrue()
        ->and(CrashRecorder::isStopCommand($server, '/STOP '))->toBeTrue()
        ->and(CrashRecorder::isStopCommand($server, 'say stop'))->toBeFalse();
});
