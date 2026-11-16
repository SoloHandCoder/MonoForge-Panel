<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Server\ScheduleTask\CreateServerScheduleTaskTest;

use Illuminate\Http\Response;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\Schedule;
use Pterodactyl\Models\Task;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);
dataset('permissionsDataProvider', fn (): array => [[[]], [[Permissions::ScheduleUpdate->value, Permissions::ControlConsole->value]]]);
dataset('taskActionPermissions', [
    'command' => ['command', 'say Test', Permissions::ControlConsole],
    'power start' => ['power', 'start', Permissions::ControlStart],
    'power stop' => ['power', 'stop', Permissions::ControlStop],
    'power restart' => ['power', 'restart', Permissions::ControlRestart],
    'power kill' => ['power', 'kill', Permissions::ControlStop],
    'backup' => ['backup', null, Permissions::BackupCreate],
    'restart when empty' => ['restart_if_empty', '2', Permissions::ControlRestart],
    'delete old logs' => ['delete_logs', '7', Permissions::FileDelete],
]);
test('task numbers accept integer strings and reject fractions', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $schedule = Schedule::factory()->create(['server_id' => $server->id]);

    $id = $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => 'command', 'payload' => 'say Test', 'time_offset' => '10', 'sequence_id' => '1', 'continue_on_failure' => '1'])
        ->assertOk()
        ->json('attributes.id');
    $task = Task::query()->findOrFail($id);
    expect($task->time_offset)->toBe(10)->and($task->continue_on_failure)->toBeTrue();

    $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => 'command', 'payload' => 'say Test', 'time_offset' => 10.5])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.rule', 'integer');
});
test('task can be created', function (array $permissions): void {
    [$user, $server] = $this->generateTestAccount($permissions);
    /** @var Schedule $schedule */
    $schedule = Schedule::factory()->create(['server_id' => $server->id]);
    $response = $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => 'command', 'payload' => 'say Test', 'time_offset' => 10, 'sequence_id' => 1]);
    $response->assertOk();
    /** @var Task $task */
    $task = Task::query()->findOrFail($response->json('attributes.id'));
    expect($task->schedule_id)->toBe($schedule->id);
    expect($task->sequence_id)->toBe(1);
    expect($task->action)->toBe('command');
    expect($task->payload)->toBe('say Test');
    expect($task->time_offset)->toBe(10);
    expect($response->json('attributes'))->toBe([
        'id' => $task->id,
        'sequence_id' => $task->sequence_id,
        'action' => $task->action,
        'payload' => $task->payload,
        'keep_backups' => $task->keep_backups,
        'time_offset' => $task->time_offset,
        'is_queued' => $task->is_queued,
        'continue_on_failure' => $task->continue_on_failure,
        'created_at' => $task->created_at->toAtomString(),
        'updated_at' => $task->updated_at->toAtomString(),
    ]);
})->with('permissionsDataProvider');
test('validation errors are returned', function (): void {
    [$user, $server] = $this->generateTestAccount();
    /** @var Schedule $schedule */
    $schedule = Schedule::factory()->create(['server_id' => $server->id]);
    $response = $this->actingAs($user)->postJson($this->link($schedule, '/tasks'))->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    foreach (['action', 'payload', 'time_offset'] as $i => $field) {
        $response->assertJsonPath("errors.{$i}.meta.rule", $field === 'payload' ? 'required_unless' : 'required');
        $response->assertJsonPath("errors.{$i}.meta.source_field", $field);
    }

    $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => 'hodor', 'payload' => 'say Test', 'time_offset' => 0])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)->assertJsonPath('errors.0.meta.rule', 'in')->assertJsonPath('errors.0.meta.source_field', 'action');
    $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => 'command', 'time_offset' => 0])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)->assertJsonPath('errors.0.meta.rule', 'required_unless')->assertJsonPath('errors.0.meta.source_field', 'payload');
    $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => 'command', 'payload' => 'say Test', 'time_offset' => 0, 'sequence_id' => 'hodor'])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)->assertJsonPath('errors.0.meta.rule', 'integer')->assertJsonPath('errors.0.meta.source_field', 'sequence_id');
});
test('backups can not be tasked if limit0', function (): void {
    [$user, $server] = $this->generateTestAccount();
    /** @var Schedule $schedule */
    $schedule = Schedule::factory()->create(['server_id' => $server->id]);
    $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => 'backup', 'time_offset' => 0])->assertStatus(Response::HTTP_FORBIDDEN)->assertJsonPath('errors.0.detail', "A backup task cannot be created when the server's backup limit is set to 0.");
    $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => 'backup', 'payload' => "file.txt\nfile2.log", 'time_offset' => 0])->assertStatus(Response::HTTP_FORBIDDEN)->assertJsonPath('errors.0.detail', "A backup task cannot be created when the server's backup limit is set to 0.");
});
test('a task cannot be created without the permission its action needs', function (string $action, ?string $payload): void {
    [$user, $server] = $this->generateTestAccount([Permissions::ScheduleUpdate->value]);
    $server->forceFill(['backup_limit' => 1])->save();
    $schedule = Schedule::factory()->create(['server_id' => $server->id]);
    $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => $action, 'payload' => $payload, 'time_offset' => 0])->assertForbidden();
    expect($schedule->tasks()->count())->toBe(0);
})->with('taskActionPermissions');
test('a task can be created with the permission its action needs', function (string $action, ?string $payload, Permissions $permission): void {
    [$user, $server] = $this->generateTestAccount([Permissions::ScheduleUpdate->value, $permission->value]);
    $server->forceFill(['backup_limit' => 1])->save();
    $schedule = Schedule::factory()->create(['server_id' => $server->id]);
    $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => $action, 'payload' => $payload, 'time_offset' => 0])->assertOk();
    $this->assertDatabaseHas('tasks', ['schedule_id' => $schedule->id, 'action' => $action, 'payload' => $payload ?? '']);
})->with('taskActionPermissions');
test('a power task only accepts known signals', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::ScheduleUpdate->value, Permissions::ControlStart->value]);
    $schedule = Schedule::factory()->create(['server_id' => $server->id]);
    $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => 'power', 'payload' => 'invalid', 'time_offset' => 0])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonPath('errors.0.meta.rule', 'in')
        ->assertJsonPath('errors.0.meta.source_field', 'payload');
});
test('error is returned if too many tasks exist for schedule', function (): void {
    config()->set('pterodactyl.client_features.schedules.per_schedule_task_limit', 2);
    [$user, $server] = $this->generateTestAccount();
    /** @var Schedule $schedule */
    $schedule = Schedule::factory()->create(['server_id' => $server->id]);
    Task::factory()->times(2)->create(['schedule_id' => $schedule->id]);
    $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => 'command', 'payload' => 'say test', 'time_offset' => 0])->assertStatus(Response::HTTP_BAD_REQUEST)->assertJsonPath('errors.0.code', 'ServiceLimitExceededException')->assertJsonPath('errors.0.detail', 'Schedules may not have more than 2 tasks associated with them. Creating this task would put this schedule over the limit.');
});
test('error is returned if schedule does not belong to server', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $server2 = $this->createServerModel(['owner_id' => $user->id]);
    /** @var Schedule $schedule */
    $schedule = Schedule::factory()->create(['server_id' => $server2->id]);
    $this->actingAs($user)->postJson("/api/client/servers/{$server->uuid}/schedules/{$schedule->id}/tasks")->assertNotFound();
});
test('error is returned if subuser does not have schedule update permissions', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::ScheduleCreate->value]);
    /** @var Schedule $schedule */
    $schedule = Schedule::factory()->create(['server_id' => $server->id]);
    $this->actingAs($user)->postJson($this->link($schedule, '/tasks'))->assertForbidden();
});

test('a backup task keeps at most the server limit of its newest backups', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $server->forceFill(['backup_limit' => 3])->save();
    $schedule = Schedule::factory()->create(['server_id' => $server->id]);

    $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => 'backup', 'time_offset' => 0, 'keep_backups' => 3])
        ->assertOk()
        ->assertJsonPath('attributes.keep_backups', 3);
    $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => 'backup', 'time_offset' => 0, 'keep_backups' => 4])
        ->assertBadRequest()
        ->assertJsonPath('errors.0.detail', 'A backup task can keep at most 3 backups on this server.');
    $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => 'command', 'payload' => 'say hi', 'time_offset' => 0, 'keep_backups' => 2])
        ->assertOk()
        ->assertJsonPath('attributes.keep_backups', null);
});

test('updating a backup task without keep_backups keeps the number it had', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $server->forceFill(['backup_limit' => 3])->save();
    $schedule = Schedule::factory()->create(['server_id' => $server->id]);
    $task = Task::factory()->create(['schedule_id' => $schedule->id, 'action' => 'backup', 'payload' => '', 'keep_backups' => 2]);

    $this->actingAs($user)->postJson($this->link($schedule, "/tasks/{$task->id}"), ['action' => 'backup', 'payload' => 'cache', 'time_offset' => 5])
        ->assertOk()
        ->assertJsonPath('attributes.keep_backups', 2);
    $this->actingAs($user)->postJson($this->link($schedule, "/tasks/{$task->id}"), ['action' => 'backup', 'time_offset' => 5, 'keep_backups' => null])
        ->assertOk()
        ->assertJsonPath('attributes.keep_backups', null);
});

test('restart when empty and log cleanup tasks take a number of hours or days', function (string $action, mixed $payload): void {
    [$user, $server] = $this->generateTestAccount();
    $schedule = Schedule::factory()->create(['server_id' => $server->id]);

    $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => $action, 'payload' => $payload, 'time_offset' => 0])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', 'payload');
})->with([
    'no hours' => ['restart_if_empty', null],
    'too many hours' => ['restart_if_empty', '73'],
    'words' => ['restart_if_empty', 'soon'],
    'no days' => ['delete_logs', '0'],
    'too many days' => ['delete_logs', '366'],
]);

test('a restart when empty task needs a game that tells who is online', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $egg = $this->cloneEggAndVariables($server->egg);
    $egg->forceFill(['name' => 'Valheim'])->save();
    $server->forceFill(['egg_id' => $egg->id])->save();
    $schedule = Schedule::factory()->create(['server_id' => $server->id]);

    $this->actingAs($user)->postJson($this->link($schedule, '/tasks'), ['action' => 'restart_if_empty', 'payload' => '2', 'time_offset' => 0])
        ->assertBadRequest();
});
