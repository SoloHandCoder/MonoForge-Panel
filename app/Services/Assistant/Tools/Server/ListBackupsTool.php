<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\Backup;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;

final class ListBackupsTool extends ServerTool
{
    public const int LIMIT = 25;

    public function name(): string
    {
        return 'list_backups';
    }

    public function description(): string
    {
        return "List the server's most recent backups with their size, state and whether they are locked, plus the backup limit.";
    }

    public function parameters(): array
    {
        return $this->schema();
    }

    public function describeCall(array $arguments): string
    {
        return 'List backups';
    }

    protected function permissions(): array
    {
        return [Permissions::BackupRead];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $server = $this->server($context);
        $backups = $server->backups()->latest('id')->limit(self::LIMIT)->get();

        $rows = $backups->map(fn (Backup $backup): array => [
            'uuid' => $backup->uuid,
            'name' => $backup->name,
            'state' => $backup->completed_at === null ? 'in progress' : ($backup->is_successful ? 'completed' : 'failed'),
            'locked' => $backup->is_locked,
            'size_mib' => round($backup->bytes / 1_048_576, 1),
            'created_at' => $backup->created_at?->toAtomString(),
            'completed_at' => $backup->completed_at?->toAtomString(),
        ])->all();

        $data = [
            'backup_limit' => $server->backup_limit,
            'backups_counted_against_limit' => $server->backups()->nonFailed()->count(),
            'backups' => $rows,
        ];

        return new ToolResult($this->json($data), ['count' => count($rows)]);
    }
}
