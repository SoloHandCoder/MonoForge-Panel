<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Assistant\Tools\SecretRedactor;

/**
 * Shows the value of a startup variable that get_startup masks because its name looks like a
 * secret. The user is asked first, and only the user sees the value: the model is told it was
 * shown, never the value itself.
 */
final class RevealStartupVariableTool extends ServerTool
{
    public function name(): string
    {
        return 'reveal_startup_variable';
    }

    public function description(): string
    {
        return 'Show the user the value of a startup variable that get_startup hides because it looks like a secret (such as RCON_PASSWORD). The user is asked to confirm first. The value is shown to the user only, never to you.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'key' => ['type' => 'string', 'description' => 'The variable key, such as "RCON_PASSWORD".'],
        ], ['key']);
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return true;
    }

    public function prepareArguments(array $arguments, ToolContext $context): array
    {
        return ['key' => mb_trim($this->string($arguments, 'key', null, 191))];
    }

    public function describeCall(array $arguments): string
    {
        return 'Show you the value of '.$this->labelArgument($arguments, 'key');
    }

    protected function permissions(): array
    {
        return [Permissions::StartupRead];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $key = mb_trim($this->string($arguments, 'key', null, 191));

        $variable = $this->server($context)->variables()->where('env_variable', $key)->first();
        throw_if(! $variable instanceof EggVariable || ! $variable->user_viewable, ToolException::class, "There is no startup variable \"{$key}\".");

        $value = (string) ($variable->server_value ?? $variable->default_value ?? '');
        $note = SecretRedactor::isSecretName($key) ? 'It is not shown to you.' : 'It is not secret: get_startup shows it to you too.';

        return new ToolResult(
            sprintf('The value of %s (%d characters) is now shown to the user in the chat. %s', $key, mb_strlen($value), $note),
            ['key' => $key, 'hidden_from_assistant' => true],
            $value === '' ? '(empty)' : $value,
        );
    }
}
