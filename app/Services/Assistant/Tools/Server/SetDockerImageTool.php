<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Pterodactyl\Contracts\Servers\UpdatesServerDockerImage;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Models\Egg;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;

/**
 * Switches the server to another of its egg's Docker images, such as another Java version,
 * under the same rules as the Startup page: only an image the egg offers, and never over an
 * image an administrator set by hand.
 */
final class SetDockerImageTool extends ServerTool
{
    public function __construct(private readonly UpdatesServerDockerImage $images) {}

    public function name(): string
    {
        return 'set_docker_image';
    }

    public function description(): string
    {
        return 'Switch the server to another Docker image its egg offers, for example a newer or older Java version (get_startup lists the choices as docker_images). It applies the next time the server starts. The user is asked to confirm first.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'image' => ['type' => 'string', 'description' => 'The image, exactly as get_startup lists it, such as "ghcr.io/pterodactyl/yolks:java_21".'],
        ], ['image']);
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return true;
    }

    public function describeCall(array $arguments): string
    {
        return 'Switch the Docker image to '.self::shorten($this->labelValue($arguments, 'image'));
    }

    protected function permissions(): array
    {
        return [Permissions::StartupDockerImage];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $image = $this->string($arguments, 'image', null, 191);
        $server = $this->server($context);
        $egg = $server->egg;
        throw_unless($egg instanceof Egg, ToolException::class, 'This server has no egg, so its Docker image cannot be changed here.');

        $images = array_values($egg->docker_images);
        throw_unless(in_array($server->image, $images, true), ToolException::class, "An administrator set this server's Docker image by hand, so it cannot be changed here. Ask your host.");
        throw_unless(in_array($image, $images, true), ToolException::class, sprintf('"%s" is not one of the images this server can use: %s.', $image, implode(', ', $images)));

        if ($image === $server->image) {
            return new ToolResult("The server already uses {$image}.", ['image' => $image]);
        }

        $this->images->update($server, $image);

        return new ToolResult("Switched the Docker image to {$image}. It applies the next time the server starts.", ['image' => $image]);
    }
}
