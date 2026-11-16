<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Schedules\PresetTaskActionsTest;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Jobs\Schedule\RunTaskJob;
use Pterodactyl\Models\Schedule;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Task;
use Pterodactyl\Services\Query\QueryStatus;
use Pterodactyl\Services\Query\ServerQuery;
use Pterodactyl\Services\Schedules\LogCleanup;
use Pterodactyl\Services\Schedules\RestartWhenEmpty;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonFile;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonPower;

uses(IntegrationTestCase::class, DatabaseTransactions::class);

/** A ServerQuery that answers with a fixed player count, or no answer at all for null. */
function players(?int $online): void
{
    app()->instance(ServerQuery::class, new class($online) extends ServerQuery
    {
        public function __construct(private readonly ?int $online) {}

        public function isUnresponsive(Server $server): bool
        {
            return false;
        }

        public function status(Server $server): ?QueryStatus
        {
            return $this->online === null ? null : new QueryStatus($this->online, 20, null, null, []);
        }
    });
}

function emptyTask(IntegrationTestCase $test, int $hours = 2): Task
{
    $schedule = Schedule::factory()->for($test->createServerModel())->create(['is_processing' => true]);

    return Task::factory()->for($schedule)->create(['action' => Task::ACTION_RESTART_IF_EMPTY, 'payload' => (string) $hours, 'sequence_id' => 1, 'time_offset' => 0]);
}

test('restart when empty waits until nobody has been online for the hours, then restarts once', function (): void {
    $task = emptyTask($this, 2);
    players(0);
    $power = new FakeDaemonPower;

    Bus::dispatchSync(new RunTaskJob($task));
    foreach ([45, 45] as $minutes) {
        $this->travel($minutes)->minutes();
        Bus::dispatchSync(new RunTaskJob($task->fresh()));
    }

    $power->assertNothingSent();

    $this->travel(30)->minutes();
    Bus::dispatchSync(new RunTaskJob($task->fresh()));
    $power->assertSentTimes('restart', 1);
    expect(Cache::get(RestartWhenEmpty::key($task)))->toBeNull();

    // The count starts again after the restart.
    $this->travel(15)->minutes();
    Bus::dispatchSync(new RunTaskJob($task->fresh()));
    $power->assertSentTimes('restart', 1);
});

test('restart when empty starts counting again when a player joins', function (): void {
    $task = emptyTask($this, 1);
    $power = new FakeDaemonPower;
    players(0);
    Bus::dispatchSync(new RunTaskJob($task));

    $this->travel(45)->minutes();
    players(3);
    Bus::dispatchSync(new RunTaskJob($task->fresh()));
    expect(Cache::get(RestartWhenEmpty::key($task)))->toBeNull();

    $this->travel(30)->minutes();
    players(0);
    Bus::dispatchSync(new RunTaskJob($task->fresh()));
    $power->assertNothingSent();
});

test('restart when empty never restarts on a game that does not answer', function (): void {
    $task = emptyTask($this, 1);
    $power = new FakeDaemonPower;
    players(0);
    Bus::dispatchSync(new RunTaskJob($task));

    $this->travel(2)->hours();
    players(null);
    Bus::dispatchSync(new RunTaskJob($task->fresh()));

    $power->assertNothingSent();
    expect(Cache::get(RestartWhenEmpty::key($task)))->toBeNull();
});

test('restart when empty does not count across a gap in the checks', function (): void {
    // The server was off (the schedule only runs while it is online) for longer than the hours.
    $task = emptyTask($this, 1);
    $power = new FakeDaemonPower;
    players(0);
    Bus::dispatchSync(new RunTaskJob($task));

    $this->travel(RestartWhenEmpty::MAX_GAP_SECONDS + 60)->seconds();
    Bus::dispatchSync(new RunTaskJob($task->fresh()));

    $power->assertNothingSent();
});

test('log cleanup deletes only old log files in the logs folder', function (): void {
    $schedule = Schedule::factory()->for($this->createServerModel())->create(['is_processing' => true]);
    $task = Task::factory()->for($schedule)->create(['action' => Task::ACTION_DELETE_LOGS, 'payload' => '7', 'sequence_id' => 1, 'time_offset' => 0]);
    $old = CarbonImmutable::now()->subDays(10)->toAtomString();
    $recent = CarbonImmutable::now()->subDays(2)->toAtomString();
    $files = new FakeDaemonFile;
    $files->directory = [
        ['name' => '2026-11-01-1.log.gz', 'file' => true, 'symlink' => false, 'modified' => $old],
        ['name' => 'debug.log', 'file' => true, 'symlink' => false, 'modified' => $old],
        ['name' => 'latest.log', 'file' => true, 'symlink' => false, 'modified' => $old],
        ['name' => '2026-11-14-1.log.gz', 'file' => true, 'symlink' => false, 'modified' => $recent],
        ['name' => 'world.zip', 'file' => true, 'symlink' => false, 'modified' => $old],
        ['name' => 'archive.log', 'file' => false, 'symlink' => false, 'modified' => $old],
        ['name' => 'linked.log', 'file' => true, 'symlink' => true, 'modified' => $old],
    ];

    Bus::dispatchSync(new RunTaskJob($task));

    $files->assertDirectoryListed(LogCleanup::DIRECTORY);
    $files->assertFilesDeleted(LogCleanup::DIRECTORY, ['2026-11-01-1.log.gz', 'debug.log']);

    expect($schedule->refresh()->is_processing)->toBeFalse();
});

test('log cleanup on a server without a logs folder does nothing', function (): void {
    $schedule = Schedule::factory()->for($this->createServerModel())->create(['is_processing' => true]);
    $task = Task::factory()->for($schedule)->create(['action' => Task::ACTION_DELETE_LOGS, 'payload' => '7', 'sequence_id' => 1, 'time_offset' => 0]);
    $files = new FakeDaemonFile;
    $files->directoryMissing = true;

    Bus::dispatchSync(new RunTaskJob($task));

    expect($files->callsFor('deleteFiles'))->toBe([])
        ->and($schedule->refresh()->is_processing)->toBeFalse();
});

test('log names exclude latest.log and anything not named like a log', function (string $name, bool $expected): void {
    expect(LogCleanup::isLogName($name))->toBe($expected);
})->with([
    ['2026-11-01-1.log.gz', true],
    ['debug.log', true],
    ['latest.log', false],
    ['../server.properties', false],
    ['notes.txt', false],
    ['log', false],
]);
