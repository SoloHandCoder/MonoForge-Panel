<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Illuminate\Support\Facades\App;
use Pterodactyl\Contracts\Backups\InitiatesBackups;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;

final class CreateBackupTool extends ServerTool
{
    public function name(): string
    {
        return 'create_backup';
    }

    public function description(): string
    {
        return 'Start a new backup of the server. It runs in the background; list_backups shows when it is done. The user is asked to confirm first.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'name' => ['type' => 'string', 'maxLength' => 191, 'description' => 'An optional name for the backup.'],
        ]);
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return true;
    }

    public function describeCall(array $arguments): string
    {
        $name = $this->labelArgument($arguments, 'name');

        return $name === '' ? 'Create a backup' : "Create the backup \"{$name}\"";
    }

    protected function permissions(): array
    {
        return [Permissions::BackupCreate];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $name = mb_trim($this->string($arguments, 'name', '', 191));

        // The action keeps per-call settings, so each backup gets its own instance.
        $backup = App::make(InitiatesBackups::class)->initiate($this->server($context), $name === '' ? null : $name);

        return new ToolResult(sprintf('Started the backup "%s" (%s).', $backup->name, $backup->uuid), ['uuid' => $backup->uuid, 'name' => $backup->name]);
    }
}
