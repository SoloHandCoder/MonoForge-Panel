<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Pterodactyl\Contracts\Servers\SendsServerCommands;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;

final class SendCommandTool extends ServerTool
{
    public const int MAX_LENGTH = 1024;

    public function __construct(private readonly SendsServerCommands $commands) {}

    public function name(): string
    {
        return 'send_command';
    }

    public function description(): string
    {
        return 'Send a command to the running server\'s console, such as "say Hello" or "whitelist add Steve". The user is asked to confirm first. Read the console afterwards to see the result.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'command' => ['type' => 'string', 'description' => 'The console command, without a leading slash unless the game needs one.'],
        ], ['command']);
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return true;
    }

    public function describeCall(array $arguments): string
    {
        return 'Run the command: '.$this->labelArgument($arguments, 'command');
    }

    protected function permissions(): array
    {
        return [Permissions::ControlConsole];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $command = mb_trim($this->string($arguments, 'command', null, self::MAX_LENGTH));
        throw_if($command === '' || preg_match('/[\r\n]/', $command) === 1, ToolException::class, 'Send one single-line command at a time.');

        $this->commands->send($this->server($context), $command);

        return new ToolResult("Sent the command: {$command}", ['command' => $command]);
    }
}
