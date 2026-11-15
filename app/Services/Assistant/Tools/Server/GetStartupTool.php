<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Assistant\Tools\SecretRedactor;

/**
 * The startup command and the variables the user may see, as the Startup page shows them.
 * Hidden variables are left out and shown as "[hidden]" in the command. The values of
 * variables named like a secret (PASSWORD, TOKEN, KEY, SECRET, RCON, API) are masked, also in
 * the command; reveal_startup_variable shows one to the user after they allow it.
 */
final class GetStartupTool extends ServerTool
{
    public function name(): string
    {
        return 'get_startup';
    }

    public function description(): string
    {
        return "Get the server's startup command, Docker image (and the images it can switch to, docker_images) and startup variables (key, value, default, whether it can be edited and its validation rules). Values of variables that look like secrets are shown as ".SecretRedactor::PLACEHOLDER.'; to show one to the user, use reveal_startup_variable.';
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

        $secret = $variables->filter(fn (EggVariable $variable): bool => SecretRedactor::isSecretName($variable->env_variable));
        $masked = (clone $server)->forceFill([
            'startup' => str_replace($secret->map(fn (EggVariable $variable): string => '{{'.$variable->env_variable.'}}')->all(), SecretRedactor::PLACEHOLDER, $server->startup),
        ]);

        $data = [
            'startup_command' => $masked->startupCommand(),
            'docker_image' => $server->image,
            // The images set_docker_image can switch to (such as other Java versions), by their label.
            'docker_images' => (object) ($server->egg->docker_images ?? []),
            'variables' => $variables->map(function (EggVariable $variable): array {
                $hidden = SecretRedactor::isSecretName($variable->env_variable);

                return [
                    'name' => $variable->name,
                    'key' => $variable->env_variable,
                    'value' => $hidden ? SecretRedactor::PLACEHOLDER : ($variable->server_value ?? $variable->default_value),
                    'default' => $hidden && ($variable->default_value ?? '') !== '' ? SecretRedactor::PLACEHOLDER : $variable->default_value,
                    'editable' => $variable->user_editable,
                    'rules' => $variable->rules,
                    'description' => $variable->description,
                ];
            })->all(),
        ];

        return new ToolResult($this->json($data), ['variables' => $variables->count(), 'hidden' => $secret->count()]);
    }
}
