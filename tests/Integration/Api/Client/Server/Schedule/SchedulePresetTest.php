<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Server\Schedule\SchedulePresetTest;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Schedule;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Task;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    config()->set('app.timezone', 'Europe/Berlin');
});

function presetsUrl(Server $server): string
{
    return "/api/client/servers/{$server->uuid}/schedules/presets";
}

function notMinecraft(ClientApiIntegrationTestCase $test, Server $server): Server
{
    $egg = (fn (): Egg => $this->cloneEggAndVariables($server->egg))->call($test);
    $egg->forceFill(['name' => 'Valheim'])->save();
    $server->forceFill(['egg_id' => $egg->id])->save();

    return $server->fresh() ?? $server;
}

/** @return list<array{action: string, payload: string, keep_backups: int|null, time_offset: int, continue_on_failure: bool}> */
function tasksOf(Schedule $schedule): array
{
    return $schedule->tasks()->orderBy('sequence_id')->get()
        ->map(fn (Task $task): array => [
            'action' => $task->action,
            'payload' => $task->payload,
            'keep_backups' => $task->keep_backups,
            'time_offset' => $task->time_offset,
            'continue_on_failure' => $task->continue_on_failure,
        ])->all();
}

// Listing

test('the owner of a Minecraft server can set up every preset', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $server->forceFill(['backup_limit' => 3])->save();

    $this->actingAs($user)->getJson(presetsUrl($server))
        ->assertOk()
        ->assertJsonPath('object', 'schedule_presets')
        ->assertJsonPath('attributes', [
            'timezone' => 'Europe/Berlin',
            'is_minecraft' => true,
            'backup_limit' => 3,
            'can_send_commands' => true,
            'presets' => [
                ['key' => 'daily_restart', 'available' => true, 'reason' => null],
                ['key' => 'backup_rotation', 'available' => true, 'reason' => null],
                ['key' => 'restart_when_empty', 'available' => true, 'reason' => null],
                ['key' => 'weekly_cleanup', 'available' => true, 'reason' => null],
            ],
        ]);
});

test('presets the server cannot run say why', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $server = notMinecraft($this, $server);
    $server->forceFill(['backup_limit' => 0])->save();

    $presets = $this->actingAs($user)->getJson(presetsUrl($server))
        ->assertOk()
        ->assertJsonPath('attributes.is_minecraft', false)
        ->json('attributes.presets');

    expect(array_column($presets, 'available', 'key'))->toBe([
        'daily_restart' => true,
        'backup_rotation' => false,
        'restart_when_empty' => false,
        'weekly_cleanup' => false,
    ])->and($presets[1]['reason'])->toBe('This server cannot keep backups.');
});

test('a subuser sees the permissions each preset needs', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::ScheduleRead->value, Permissions::ScheduleCreate->value, Permissions::ControlRestart->value]);
    $server->forceFill(['backup_limit' => 2])->save();

    $response = $this->actingAs($user)->getJson(presetsUrl($server))->assertOk();

    expect(array_column($response->json('attributes.presets'), 'reason', 'key'))->toBe([
        'daily_restart' => null,
        'backup_rotation' => 'You need the backup.create permission.',
        'restart_when_empty' => null,
        'weekly_cleanup' => 'You need the file.delete permission.',
    ])->and($response->json('attributes.can_send_commands'))->toBeFalse();
});

test('a subuser without schedule read access cannot list presets', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::ControlConsole->value]);

    $this->actingAs($user)->getJson(presetsUrl($server))->assertForbidden();
});

// Daily restart

test('a daily restart warns five and one minutes ahead, then restarts at the time picked', function (): void {
    [$user, $server] = $this->generateTestAccount();

    $response = $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'daily_restart', 'hour' => 4, 'minute' => 0, 'warning_minutes' => 5, 'warning_command' => 'broadcast'])
        ->assertOk()
        ->assertJsonPath('attributes.preset', 'daily_restart')
        ->assertJsonPath('attributes.name', 'Daily restart at 04:00 (Europe/Berlin)')
        ->assertJsonPath('attributes.is_active', true)
        ->assertJsonPath('attributes.only_when_online', true)
        ->assertJsonPath('attributes.cron', ['day_of_week' => '*', 'day_of_month' => '*', 'month' => '*', 'hour' => '3', 'minute' => '55']);

    $schedule = Schedule::query()->findOrFail($response->json('attributes.id'));
    // Minecraft always warns with `say`; the command name is only for other games.
    expect(tasksOf($schedule))->toBe([
        ['action' => 'command', 'payload' => 'say Server restarting in 5 minutes.', 'keep_backups' => null, 'time_offset' => 0, 'continue_on_failure' => true],
        ['action' => 'command', 'payload' => 'say Server restarting in 1 minute.', 'keep_backups' => null, 'time_offset' => 240, 'continue_on_failure' => true],
        ['action' => 'power', 'payload' => 'restart', 'keep_backups' => null, 'time_offset' => 60, 'continue_on_failure' => false],
    ])->and($response->json('attributes.relationships.tasks.data'))->toHaveCount(3);
    $this->assertActivityOn('server:schedule.preset', $server, $schedule);
});

test('a daily restart just after midnight starts its warning the evening before', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $server = notMinecraft($this, $server);

    $id = $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'daily_restart', 'hour' => 0, 'minute' => 2, 'warning_minutes' => 1, 'warning_command' => 'broadcast'])
        ->assertOk()
        ->assertJsonPath('attributes.cron.hour', '0')
        ->assertJsonPath('attributes.cron.minute', '1')
        ->json('attributes.id');
    expect(tasksOf(Schedule::query()->findOrFail($id))[0]['payload'])->toBe('broadcast Server restarting in 1 minute.');

    $schedule = Schedule::query()->findOrFail($id);
    $schedule->forceFill(['is_active' => false])->save();
    $id = $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'daily_restart', 'hour' => 0, 'minute' => 2, 'warning_minutes' => 5])
        ->assertOk()
        ->assertJsonPath('attributes.cron.hour', '23')
        ->assertJsonPath('attributes.cron.minute', '57')
        ->json('attributes.id');
    expect(tasksOf(Schedule::query()->findOrFail($id))[0]['payload'])->toBe('say Server restarting in 5 minutes.');
});

test('a daily restart without a warning only restarts and needs no console access', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::ScheduleCreate->value, Permissions::ControlRestart->value]);

    $id = $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'daily_restart', 'hour' => 6, 'minute' => 30, 'warning_minutes' => 0])
        ->assertOk()
        ->json('attributes.id');

    expect(tasksOf(Schedule::query()->findOrFail($id)))->toBe([
        ['action' => 'power', 'payload' => 'restart', 'keep_backups' => null, 'time_offset' => 0, 'continue_on_failure' => false],
    ]);
});

test('a daily restart that warns players needs console access', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::ScheduleCreate->value, Permissions::ControlRestart->value]);

    $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'daily_restart', 'hour' => 6, 'minute' => 30, 'warning_minutes' => 5])
        ->assertForbidden();
    expect($server->schedules()->count())->toBe(0);
});

test('a daily restart rejects a time or warning out of range and a command that is not one word', function (array $input, string $field): void {
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'daily_restart', 'hour' => 4, 'minute' => 0, 'warning_minutes' => 5, ...$input])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', $field);
})->with([
    'hour' => [['hour' => 24], 'hour'],
    'minute' => [['minute' => -1], 'minute'],
    'warning' => [['warning_minutes' => 16], 'warning_minutes'],
    'command' => [['warning_command' => 'say hi; stop'], 'warning_command'],
]);

// Backup rotation

test('a backup rotation backs up every few hours and keeps the number picked', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $server->forceFill(['backup_limit' => 3])->save();

    $id = $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'backup_rotation', 'every_hours' => 6, 'keep' => 3])
        ->assertOk()
        ->assertJsonPath('attributes.preset', 'backup_rotation')
        ->assertJsonPath('attributes.name', 'Backup every 6 hours, keep 3')
        ->assertJsonPath('attributes.cron.hour', '*/6')
        ->assertJsonPath('attributes.cron.minute', (string) ($server->id % 60))
        ->assertJsonPath('attributes.relationships.tasks.data.0.attributes.keep_backups', 3)
        ->json('attributes.id');

    expect(tasksOf(Schedule::query()->findOrFail($id)))->toBe([
        ['action' => 'backup', 'payload' => '', 'keep_backups' => 3, 'time_offset' => 0, 'continue_on_failure' => false],
    ]);
});

test('a backup rotation cannot keep more than the server limit', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $server->forceFill(['backup_limit' => 3])->save();

    $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'backup_rotation', 'every_hours' => 6, 'keep' => 4])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', 'keep');
    $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'backup_rotation', 'every_hours' => 5, 'keep' => 2])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', 'every_hours');
});

test('a backup rotation needs a server that keeps backups', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $server->forceFill(['backup_limit' => 0])->save();

    $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'backup_rotation', 'every_hours' => 6, 'keep' => 1])
        ->assertBadRequest()
        ->assertJsonPath('errors.0.detail', 'This server cannot keep backups.');
});

test('a backup rotation needs backup.create', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::ScheduleCreate->value]);
    $server->forceFill(['backup_limit' => 3])->save();

    $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'backup_rotation', 'every_hours' => 6, 'keep' => 1])->assertForbidden();
});

test('a backup rotation deletes old backups, so it needs backup.delete too', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::ScheduleRead->value, Permissions::ScheduleCreate->value, Permissions::BackupCreate->value]);
    $server->forceFill(['backup_limit' => 3])->save();

    $presets = $this->actingAs($user)->getJson(presetsUrl($server))->assertOk()->json('attributes.presets');
    expect(array_column($presets, 'reason', 'key')['backup_rotation'])->toBe('You need the backup.delete permission.');
    $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'backup_rotation', 'every_hours' => 6, 'keep' => 1])->assertForbidden();
});

// Restart when empty and weekly cleanup

test('a restart when empty checks every 15 minutes', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $offset = $server->id % 15;

    $id = $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'restart_when_empty', 'hours' => 2])
        ->assertOk()
        ->assertJsonPath('attributes.name', 'Restart when empty for 2 hours')
        ->assertJsonPath('attributes.cron.minute', implode(',', [$offset, $offset + 15, $offset + 30, $offset + 45]))
        ->assertJsonPath('attributes.only_when_online', true)
        ->json('attributes.id');

    expect(tasksOf(Schedule::query()->findOrFail($id)))->toBe([
        ['action' => 'restart_if_empty', 'payload' => '2', 'keep_backups' => null, 'time_offset' => 0, 'continue_on_failure' => false],
    ]);
});

test('restart when empty and weekly cleanup are only for games that support them', function (string $preset, array $input): void {
    [$user, $server] = $this->generateTestAccount();
    $server = notMinecraft($this, $server);

    $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => $preset, ...$input])->assertBadRequest();
    expect($server->schedules()->count())->toBe(0);
})->with([
    'restart when empty' => ['restart_when_empty', ['hours' => 2]],
    'weekly cleanup' => ['weekly_cleanup', ['days' => 7]],
]);

test('a weekly cleanup deletes logs older than the days picked, on Mondays', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::ScheduleCreate->value, Permissions::FileDelete->value]);

    $id = $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'weekly_cleanup', 'days' => 14])
        ->assertOk()
        ->assertJsonPath('attributes.cron.day_of_week', '1')
        ->assertJsonPath('attributes.only_when_online', false)
        ->json('attributes.id');

    expect(tasksOf(Schedule::query()->findOrFail($id)))->toBe([
        ['action' => 'delete_logs', 'payload' => '14', 'keep_backups' => null, 'time_offset' => 0, 'continue_on_failure' => false],
    ]);
});

// One per preset

test('a preset that is already active cannot be set up again until it is disabled', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $input = ['preset' => 'restart_when_empty', 'hours' => 2];

    $id = $this->actingAs($user)->postJson(presetsUrl($server), $input)->assertOk()->json('attributes.id');
    $this->actingAs($user)->postJson(presetsUrl($server), $input)
        ->assertBadRequest()
        ->assertJsonPath('errors.0.detail', 'Restart when empty is already set up on this server. Disable it first to set it up again.');

    Schedule::query()->findOrFail($id)->forceFill(['is_active' => false])->save();
    $this->actingAs($user)->postJson(presetsUrl($server), $input)->assertOk();
    expect($server->schedules()->where('preset', 'restart_when_empty')->count())->toBe(2);
});

test('an unknown preset and a preset without its options are rejected', function (): void {
    [$user, $server] = $this->generateTestAccount();

    $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'hodor'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', 'preset');
    $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'weekly_cleanup'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', 'days');
});

test('a subuser without schedule.create cannot set up a preset', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::ScheduleRead->value, Permissions::FileDelete->value]);

    $this->actingAs($user)->postJson(presetsUrl($server), ['preset' => 'weekly_cleanup', 'days' => 7])->assertForbidden();
});

test('preset schedules show their preset in the schedule list', function (): void {
    [$user, $server] = $this->generateTestAccount();
    Schedule::factory()->for($server)->create(['preset' => 'daily_restart']);
    Schedule::factory()->for($server)->create();

    $presets = $this->actingAs($user)->getJson("/api/client/servers/{$server->uuid}/schedules")
        ->assertOk()
        ->json('data.*.attributes.preset');

    expect($presets)->toEqualCanonicalizing(['daily_restart', null]);
});
