<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Schedules;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Enum\SchedulePreset;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Schedule;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Task;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Games\GameResolver;
use Pterodactyl\Services\Query\ServerQuery;
use Throwable;

/**
 * The Quick setup presets on a server's Schedules page. A preset is only a shortcut: it makes an
 * ordinary schedule with ordinary tasks, tagged with the preset, which can then be edited, run,
 * disabled or deleted like any other.
 *
 * The cheap-CPU rules apply: each server's preset schedules run at a minute derived from its id,
 * so a node's servers do not all start their backups or checks at the same second.
 *
 * @phpstan-type PresetTask array{action: string, payload: string, keep_backups: int|null, time_offset: int, continue_on_failure: bool}
 * @phpstan-type PresetPlan array{name: string, minute: string, hour: string, day_of_month: string, month: string, day_of_week: string, only_when_online: bool, tasks: list<PresetTask>}
 */
final readonly class SchedulePresets
{
    /** The hours a backup preset may run every (each divides a day, so the cron stays exact). */
    public const array BACKUP_INTERVALS = [1, 2, 3, 4, 6, 8, 12, 24];

    /** The most minutes a daily restart warns players before it restarts. */
    public const int MAX_WARNING_MINUTES = 15;

    /**
     * Each preset's rules. `keep` is capped at the server's backup limit.
     *
     * @return NormalizedValidationRules
     */
    public static function rules(SchedulePreset $preset, Server $server): array
    {
        return match ($preset) {
            SchedulePreset::DailyRestart => [
                'hour' => ['required', 'integer', 'min:0', 'max:23'],
                'minute' => ['required', 'integer', 'min:0', 'max:59'],
                'warning_minutes' => ['required', 'integer', 'min:0', 'max:'.self::MAX_WARNING_MINUTES],
                // Only the console command's name: the warning text is the panel's own.
                'warning_command' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_.:\/-]{1,32}$/'],
            ],
            SchedulePreset::BackupRotation => [
                'every_hours' => ['required', 'integer', Rule::in(self::BACKUP_INTERVALS)],
                'keep' => ['required', 'integer', 'min:1', 'max:'.max(1, $server->backup_limit)],
            ],
            SchedulePreset::RestartWhenEmpty => [
                'hours' => ['required', 'integer', 'min:1', 'max:'.Task::MAX_EMPTY_HOURS],
            ],
            SchedulePreset::WeeklyCleanup => [
                'days' => ['required', 'integer', 'min:1', 'max:'.Task::MAX_LOG_DAYS],
            ],
        };
    }

    /**
     * The permissions the preset's tasks need, besides schedule.create. A daily restart that warns
     * players first also sends console commands.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<Permissions>
     */
    public static function permissions(SchedulePreset $preset, array $input = []): array
    {
        return match ($preset) {
            SchedulePreset::DailyRestart => (int) (is_numeric($input['warning_minutes'] ?? null) ? $input['warning_minutes'] : 0) > 0
                ? [Permissions::ControlRestart, Permissions::ControlConsole]
                : [Permissions::ControlRestart],
            // Rotation deletes the oldest backups, so it needs the right to delete them too.
            SchedulePreset::BackupRotation => [Permissions::BackupCreate, Permissions::BackupDelete],
            SchedulePreset::RestartWhenEmpty => [Permissions::ControlRestart],
            SchedulePreset::WeeklyCleanup => [Permissions::FileDelete],
        };
    }

    /** Whether the server runs Minecraft Java, whose console has `say` and whose logs live in `logs/`. */
    public static function isMinecraft(Server $server): bool
    {
        return GameResolver::isMinecraft($server);
    }

    /** Why the server cannot have the preset at all, whoever asks, or null. */
    public static function unavailable(SchedulePreset $preset, Server $server): ?string
    {
        return match ($preset) {
            SchedulePreset::DailyRestart => null,
            SchedulePreset::BackupRotation => $server->backup_limit > 0 ? null : 'This server cannot keep backups.',
            SchedulePreset::RestartWhenEmpty => ServerQuery::supports($server) ? null : 'This needs a game that tells the panel who is online (Minecraft Java for now).',
            SchedulePreset::WeeklyCleanup => self::isMinecraft($server) ? null : 'Weekly cleanup is for Minecraft servers.',
        };
    }

    /** Why the user cannot set up the preset on the server, or null when they can. */
    public static function reason(SchedulePreset $preset, Server $server, User $user): ?string
    {
        $unavailable = self::unavailable($preset, $server);
        if ($unavailable !== null) {
            return $unavailable;
        }

        foreach ([Permissions::ScheduleCreate, ...self::permissions($preset)] as $permission) {
            if (! $user->can($permission->value, $server)) {
                return sprintf('You need the %s permission.', $permission->value);
            }
        }

        return null;
    }

    /** The timezone schedules run in: the panel's. */
    public static function timezone(): string
    {
        $timezone = config('app.timezone');

        return $timezone !== '' ? $timezone : 'UTC';
    }

    /**
     * Creates the preset's schedule and its tasks. The caller has checked the permissions. A
     * server has at most one active schedule per preset.
     *
     * @param  array<array-key, mixed>  $input
     *
     * @throws DisplayException
     */
    public function create(Server $server, SchedulePreset $preset, array $input): Schedule
    {
        $unavailable = self::unavailable($preset, $server);
        throw_if($unavailable !== null, DisplayException::class, $unavailable);

        /** @var array<string, mixed> $validated */
        $validated = Validator::validate($input, self::rules($preset, $server));
        $plan = $this->plan($preset, $server, $validated);

        $limit = config('pterodactyl.client_features.schedules.per_schedule_task_limit');
        throw_if(count($plan['tasks']) > $limit, DisplayException::class, "Schedules may not have more than $limit tasks associated with them.");

        $schedule = DB::transaction(function () use ($server, $preset, $plan): Schedule {
            // One at a time per server, so two clicks cannot set up the same preset twice.
            Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            throw_if(
                $server->schedules()->where('preset', $preset->value)->where('is_active', true)->exists(),
                DisplayException::class,
                sprintf('%s is already set up on this server. Disable it first to set it up again.', $preset->label()),
            );

            $schedule = $server->schedules()->make([
                'name' => $plan['name'],
                'preset' => $preset->value,
                'cron_minute' => $plan['minute'],
                'cron_hour' => $plan['hour'],
                'cron_day_of_month' => $plan['day_of_month'],
                'cron_month' => $plan['month'],
                'cron_day_of_week' => $plan['day_of_week'],
                'is_active' => true,
                'only_when_online' => $plan['only_when_online'],
            ]);

            try {
                $schedule->next_run_at = $schedule->getNextRunDate();
            } catch (Throwable) {
                throw new DisplayException('The cron data provided does not evaluate to a valid expression.');
            }

            $schedule->save();

            foreach ($plan['tasks'] as $index => $task) {
                $schedule->tasks()->create([...$task, 'sequence_id' => $index + 1]);
            }

            return $schedule;
        });

        Activity::event('server:schedule.preset')
            ->subject($server, $schedule)
            ->property(['name' => $schedule->name, 'preset' => $preset->label()])
            ->log();

        return $schedule->refresh()->load('tasks');
    }

    /**
     * The schedule and tasks a preset makes from validated input.
     *
     * @param  array<string, mixed>  $input
     * @return PresetPlan
     */
    public function plan(SchedulePreset $preset, Server $server, array $input): array
    {
        $int = fn (string $key): int => is_numeric($input[$key] ?? null) ? (int) $input[$key] : 0;
        // The minute each of this server's preset schedules runs at, spread across servers.
        $spread = $server->id % 60;

        return match ($preset) {
            SchedulePreset::DailyRestart => $this->dailyRestart($server, $int('hour'), $int('minute'), $int('warning_minutes'), is_string($input['warning_command'] ?? null) ? $input['warning_command'] : null),
            SchedulePreset::BackupRotation => [
                'name' => sprintf('Backup every %s, keep %d', $this->plural($int('every_hours'), 'hour'), $int('keep')),
                'minute' => (string) $spread,
                'hour' => $int('every_hours') >= 24 ? (string) (2 + $server->id % 4) : ($int('every_hours') === 1 ? '*' : '*/'.$int('every_hours')),
                'day_of_month' => '*',
                'month' => '*',
                'day_of_week' => '*',
                'only_when_online' => true,
                'tasks' => [$this->task(Task::ACTION_BACKUP, '', keep: $int('keep'))],
            ],
            SchedulePreset::RestartWhenEmpty => [
                'name' => sprintf('Restart when empty for %s', $this->plural($int('hours'), 'hour')),
                // Every 15 minutes: one cached player count per check.
                'minute' => implode(',', array_map(fn (int $quarter): int => $server->id % 15 + $quarter * 15, [0, 1, 2, 3])),
                'hour' => '*',
                'day_of_month' => '*',
                'month' => '*',
                'day_of_week' => '*',
                'only_when_online' => true,
                'tasks' => [$this->task(Task::ACTION_RESTART_IF_EMPTY, (string) $int('hours'))],
            ],
            SchedulePreset::WeeklyCleanup => [
                'name' => sprintf('Weekly cleanup of logs older than %s', $this->plural($int('days'), 'day')),
                'minute' => (string) $spread,
                'hour' => '5',
                'day_of_month' => '*',
                'month' => '*',
                'day_of_week' => '1',
                'only_when_online' => false,
                'tasks' => [$this->task(Task::ACTION_DELETE_LOGS, (string) $int('days'))],
            ],
        };
    }

    /**
     * Warns players `warning` minutes ahead (and again one minute ahead), then restarts at the
     * time picked, in the panel's timezone. The schedule itself starts at the first warning.
     *
     * @return PresetPlan
     */
    private function dailyRestart(Server $server, int $hour, int $minute, int $warning, ?string $command): array
    {
        $start = (($hour * 60 + $minute - $warning) % 1440 + 1440) % 1440;
        $verb = self::isMinecraft($server) || $command === null || $command === '' ? 'say' : $command;
        $warn = fn (int $minutes): string => sprintf('%s Server restarting in %s.', $verb, $this->plural($minutes, 'minute'));

        $tasks = match (true) {
            $warning === 0 => [$this->task(Task::ACTION_POWER, 'restart')],
            $warning === 1 => [
                $this->task(Task::ACTION_COMMAND, $warn(1), continueOnFailure: true),
                $this->task(Task::ACTION_POWER, 'restart', 60),
            ],
            default => [
                $this->task(Task::ACTION_COMMAND, $warn($warning), continueOnFailure: true),
                $this->task(Task::ACTION_COMMAND, $warn(1), ($warning - 1) * 60, continueOnFailure: true),
                $this->task(Task::ACTION_POWER, 'restart', 60),
            ],
        };

        return [
            'name' => sprintf('Daily restart at %02d:%02d (%s)', $hour, $minute, self::timezone()),
            'minute' => (string) ($start % 60),
            'hour' => (string) intdiv($start, 60),
            'day_of_month' => '*',
            'month' => '*',
            'day_of_week' => '*',
            'only_when_online' => true,
            'tasks' => $tasks,
        ];
    }

    /** @return PresetTask */
    private function task(string $action, string $payload, int $offset = 0, bool $continueOnFailure = false, ?int $keep = null): array
    {
        return [
            'action' => $action,
            'payload' => $payload,
            'keep_backups' => $keep,
            'time_offset' => $offset,
            'continue_on_failure' => $continueOnFailure,
        ];
    }

    private function plural(int $count, string $unit): string
    {
        return $count === 1 ? "1 $unit" : "$count {$unit}s";
    }
}
