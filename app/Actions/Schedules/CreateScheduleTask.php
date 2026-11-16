<?php

declare(strict_types=1);

namespace Pterodactyl\Actions\Schedules;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ValidatedInput;
use Illuminate\Validation\Rule;
use Pterodactyl\Contracts\Schedules\CreatesScheduleTasks;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Exceptions\Http\HttpForbiddenException;
use Pterodactyl\Exceptions\Service\ServiceLimitExceededException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Schedule;
use Pterodactyl\Models\Task;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Query\ServerQuery;

final readonly class CreateScheduleTask implements CreatesScheduleTasks
{
    /**
     * A power task's payload must name the power action to send; a restart-when-empty task's is
     * the hours to wait and a log cleanup's the days to keep. Only a backup task keeps a number
     * of backups.
     *
     * @return NormalizedValidationRules
     */
    public static function rules(mixed $action = null): array
    {
        $payload = match ($action) {
            Task::ACTION_POWER => ['required', 'string', Rule::in(Task::POWER_ACTIONS)],
            Task::ACTION_BACKUP => ['nullable', 'string'],
            Task::ACTION_RESTART_IF_EMPTY => ['required', 'integer', 'min:1', 'max:'.Task::MAX_EMPTY_HOURS],
            Task::ACTION_DELETE_LOGS => ['required', 'integer', 'min:1', 'max:'.Task::MAX_LOG_DAYS],
            default => ['required_unless:action,backup', 'string', 'nullable'],
        };

        return [
            'action' => ['required', Rule::in(Task::ACTIONS)],
            'payload' => $payload,
            'keep_backups' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'time_offset' => ['required', 'integer', 'min:0', 'max:900'],
            'sequence_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'continue_on_failure' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * The task's attributes from validated input, after the checks that need the server: a backup
     * task needs a server that keeps backups and keeps at most its limit, and a restart-when-empty
     * task a game that tells who is online. A backup task updated without `keep_backups` keeps
     * the number it had.
     *
     * @return array{action: string, payload: string, keep_backups: int|null, time_offset: int, continue_on_failure: bool}
     *
     * @throws HttpForbiddenException
     * @throws DisplayException
     */
    public static function attributes(Schedule $schedule, ValidatedInput $validated, ?int $currentKeep = null): array
    {
        $action = $validated->string('action')->toString();
        $server = $schedule->server;
        throw_if($action === Task::ACTION_BACKUP && $server->backup_limit === 0, HttpForbiddenException::class, "A backup task cannot be created when the server's backup limit is set to 0.");
        throw_if($action === Task::ACTION_RESTART_IF_EMPTY && ! ServerQuery::supports($server), DisplayException::class, 'A restart when empty task needs a game that tells the panel who is online (Minecraft Java for now).');

        $keep = match (true) {
            $action !== Task::ACTION_BACKUP => null,
            $validated->filled('keep_backups') => $validated->integer('keep_backups'),
            $validated->has('keep_backups') => null,
            default => $currentKeep,
        };
        throw_if($keep !== null && $keep > $server->backup_limit, DisplayException::class, sprintf('A backup task can keep at most %d backups on this server.', $server->backup_limit));

        // Keeping only the newest backups deletes the older ones the task made.
        $user = Auth::user();
        throw_if($keep !== null && $keep !== $currentKeep && $user instanceof User && ! $user->can(Permissions::BackupDelete->value, $server), HttpForbiddenException::class, 'Keeping only the newest backups deletes older ones, so it needs the backup.delete permission.');

        $payload = $validated['payload'] ?? '';

        return [
            'action' => $action,
            'payload' => is_scalar($payload) ? (string) $payload : '',
            'keep_backups' => $keep,
            'time_offset' => $validated->integer('time_offset'),
            'continue_on_failure' => $validated->boolean('continue_on_failure'),
        ];
    }

    public function create(Schedule $schedule, array $input): Task
    {
        $validated = Validator::make($input, self::rules($input['action'] ?? null))->safe();
        $attributes = self::attributes($schedule, $validated);

        $requested = $validated->filled('sequence_id') ? $validated->integer('sequence_id') : null;

        $task = DB::transaction(function () use ($schedule, $attributes, $requested): Task {
            $schedule = $schedule->newQuery()->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();
            $limit = config('pterodactyl.client_features.schedules.per_schedule_task_limit');
            throw_if($schedule->tasks()->count() >= $limit, ServiceLimitExceededException::class, "Schedules may not have more than $limit tasks associated with them. Creating this task would put this schedule over the limit.");

            $next = ($schedule->tasks()->orderByDesc('sequence_id')->first()->sequence_id ?? 0) + 1;
            $requested ??= $next;

            $sequenceId = $next;
            if ($requested < $next) {
                $schedule->tasks()
                    ->where('sequence_id', '>=', $requested)
                    ->increment('sequence_id');
                $sequenceId = $requested;
            }

            return $schedule->tasks()->create([...$attributes, 'sequence_id' => $sequenceId]);
        });

        Activity::event('server:task.create')
            ->subject($schedule->server, $schedule, $task)
            ->property(['name' => $schedule->name, 'action' => $task->action, 'payload' => $task->payload])
            ->log();

        return $task;
    }
}
