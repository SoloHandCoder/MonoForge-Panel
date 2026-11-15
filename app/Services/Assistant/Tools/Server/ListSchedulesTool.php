<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\Schedule;
use Pterodactyl\Models\Task;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;

final class ListSchedulesTool extends ServerTool
{
    public const int LIMIT = 50;

    public function name(): string
    {
        return 'list_schedules';
    }

    public function description(): string
    {
        return "List the server's schedules with their cron timing, whether they are active, their last and next run, and their tasks.";
    }

    public function parameters(): array
    {
        return $this->schema();
    }

    public function describeCall(array $arguments): string
    {
        return 'List schedules';
    }

    protected function permissions(): array
    {
        return [Permissions::ScheduleRead];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $schedules = $this->server($context)->schedules()->with('tasks')->orderBy('id')->limit(self::LIMIT)->get();

        $rows = $schedules->map(fn (Schedule $schedule): array => [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'cron' => implode(' ', [$schedule->cron_minute, $schedule->cron_hour, $schedule->cron_day_of_month, $schedule->cron_month, $schedule->cron_day_of_week]),
            'active' => $schedule->is_active,
            'only_when_online' => $schedule->only_when_online,
            'running' => $schedule->is_processing,
            'last_run_at' => $schedule->last_run_at?->toAtomString(),
            'next_run_at' => $schedule->next_run_at?->toAtomString(),
            'tasks' => $schedule->tasks->sortBy('sequence_id')->values()->map(fn (Task $task): array => [
                'sequence' => $task->sequence_id,
                'action' => $task->action,
                'payload' => $task->payload,
                'delay_seconds' => $task->time_offset,
                'continue_on_failure' => $task->continue_on_failure,
            ])->all(),
        ])->all();

        return new ToolResult($this->json(['schedules' => $rows]), ['count' => count($rows)]);
    }
}
