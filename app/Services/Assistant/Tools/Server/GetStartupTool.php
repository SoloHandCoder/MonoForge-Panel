<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;

/**
 * The startup command and the variables the user may see, as the Startup page shows them.
 * Hidden variables are left out and shown as "[hidden]" in the command.
 */
final class GetStartupTool extends ServerTool
{
    public function name(): string
    {
        return 'get_startup';
    }

    public function description(): string
    {
        return "Get the server's startup command, Docker image and startup variables (key, value, default, whether it can be edited and its validation rules).";
    }

    public function parameters(): array
    {
        return $this->schema();
    }

    public function describeCall(array $arguments): string
    {
        return 'Read the startup settings';
    }

    protected function permissions(): array
    {
        return [Permissions::StartupRead];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $server = $this->server($context);
        $variables = $server->variables()->where('user_viewable', true)->get();

        $data = [
            'startup_command' => $server->startupCommand(),
            'docker_image' => $server->image,
            'variables' => $variables->map(fn (EggVariable $variable): array => [
                'name' => $variable->name,
                'key' => $variable->env_variable,
                'value' => $variable->server_value ?? $variable->default_value,
                'default' => $variable->default_value,
                'editable' => $variable->user_editable,
                'rules' => $variable->rules,
                'description' => $variable->description,
            ])->all(),
        ];

        return new ToolResult($this->json($data), ['variables' => $variables->count()]);
    }
}
