<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Pterodactyl\Contracts\Servers\UpdatesServerVariable;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;

/**
 * Changes one startup variable, under the same rules as the Startup page: only variables the
 * user can see and edit, validated with the egg's rules for the variable.
 */
final class UpdateStartupVariableTool extends ServerTool
{
    public function __construct(private readonly UpdatesServerVariable $variables) {}

    public function name(): string
    {
        return 'update_startup_variable';
    }

    public function description(): string
    {
        return 'Change the value of an editable startup variable (see get_startup for the keys and their rules). It applies the next time the server starts. The user is asked to confirm first.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'key' => ['type' => 'string', 'description' => 'The variable key, such as "SERVER_JARFILE".'],
            'value' => ['type' => 'string', 'description' => 'The new value; an empty string clears it.'],
        ], ['key', 'value']);
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return true;
    }

    public function describeCall(array $arguments): string
    {
        return sprintf('Set %s to "%s"', $this->labelArgument($arguments, 'key'), $this->labelArgument($arguments, 'value'));
    }

    protected function permissions(): array
    {
        return [Permissions::StartupUpdate];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $key = $this->string($arguments, 'key', null, 191);
        $value = $this->string($arguments, 'value', '', 4096);
        $server = $this->server($context);

        $variable = $server->variables()->where('env_variable', $key)->first();
        throw_if(! $variable instanceof EggVariable || ! $variable->user_viewable, ToolException::class, "There is no startup variable \"{$key}\".");

        throw_unless($variable->user_editable, ToolException::class, "The startup variable \"{$key}\" is read-only.");

        $rules = $variable->rules ?? '';
        Validator::make(
            ['value' => $value === '' ? null : $value],
            ['value' => ['present', ...(Str::startsWith($rules, 'regex:') ? [$rules] : array_filter(explode('|', $rules), fn (string $rule): bool => $rule !== ''))]],
            [],
            ['value' => $variable->name],
        )->validate();

        $this->variables->update($server, $variable->id, $value);

        return new ToolResult("Set {$key} to \"{$value}\". It applies the next time the server starts.", ['key' => $key, 'value' => $value]);
    }
}
