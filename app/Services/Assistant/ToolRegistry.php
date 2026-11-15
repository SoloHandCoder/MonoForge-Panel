<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant;

use InvalidArgumentException;
use Pterodactyl\Contracts\Assistant\AssistantTool;
use Pterodactyl\Contracts\Assistant\RestrictedTool;
use Pterodactyl\Contracts\Assistant\ToolContext;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * The tools each assistant can use. Tools are registered once (a service provider calls
 * register('server'|'admin', $tool)); for() returns those the context's user may use.
 */
class ToolRegistry
{
    /** @var array<string, array<string, AssistantTool>> */
    private array $tools = [
        ToolContext::SCOPE_SERVER => [],
        ToolContext::SCOPE_ADMIN => [],
    ];

    /**
     * The OpenAI function definitions of the given tools.
     *
     * @param  list<AssistantTool>  $tools
     * @return list<array<string, mixed>>
     */
    public static function definitions(array $tools): array
    {
        return array_map(function (AssistantTool $tool): array {
            $parameters = $tool->parameters();

            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $parameters === [] ? ['type' => 'object', 'properties' => (object) []] : $parameters,
                ],
            ];
        }, $tools);
    }

    public function register(string $scope, AssistantTool $tool): void
    {
        throw_unless(isset($this->tools[$scope]), InvalidArgumentException::class, "Unknown assistant scope [{$scope}].");

        $name = $tool->name();
        throw_unless(preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) === 1, InvalidArgumentException::class, "An assistant tool name must be snake_case, [{$name}] is not.");

        $this->tools[$scope][$name] = $tool;
    }

    /**
     * The tools the context's user may use right now: none in the admin assistant for a
     * non-root user or in the server assistant without `assistant.use` on the server, and
     * never a RestrictedTool whose allowed() says no.
     *
     * @return list<AssistantTool>
     */
    public function for(string $scope, ToolContext $ctx): array
    {
        if ($scope !== $ctx->scope || ! $this->contextAllowed($ctx)) {
            return [];
        }

        $tools = [];
        foreach ($this->tools[$scope] ?? [] as $tool) {
            if ($tool instanceof RestrictedTool) {
                try {
                    if (! $tool->allowed($ctx)) {
                        continue;
                    }
                } catch (Throwable $exception) {
                    report($exception);

                    continue;
                }
            }

            $tools[] = $tool;
        }

        return $tools;
    }

    /** The named tool if the context's user may use it now. */
    public function find(string $name, ToolContext $ctx): ?AssistantTool
    {
        foreach ($this->for($ctx->scope, $ctx) as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /** Whether a tool of this name is registered at all, whoever asks. */
    public function has(string $scope, string $name): bool
    {
        return isset($this->tools[$scope][$name]);
    }

    private function contextAllowed(ToolContext $ctx): bool
    {
        if ($ctx->scope === ToolContext::SCOPE_ADMIN) {
            return (bool) $ctx->user->root_admin;
        }

        return $ctx->server instanceof Server && $ctx->user->can(Permissions::AssistantUse->value, $ctx->server);
    }
}
