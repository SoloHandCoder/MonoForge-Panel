<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Illuminate\Http\Response;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Assistant\Tools\OutputRedactor;
use Pterodactyl\Services\Assistant\Tools\ServerPaths;

/**
 * Creates or overwrites a text file. Like the file manager, overwriting needs file.update
 * and creating needs file.create.
 */
final class WriteFileTool extends ServerTool
{
    public const int MAX_BYTES = 262_144;

    public function __construct(
        private readonly WritesFileContents $writer,
        private readonly ListsDirectories $lister,
    ) {}

    public function name(): string
    {
        return 'write_file';
    }

    public function description(): string
    {
        return 'Create a text file or replace the whole contents of an existing one (at most 256 KB). The user is asked to confirm first. Always write the complete file, not only the changed part.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'path' => ['type' => 'string', 'description' => 'The file to write, such as "/server.properties".'],
            'content' => ['type' => 'string', 'description' => 'The complete new contents of the file.'],
        ], ['path', 'content']);
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return true;
    }

    public function describeCall(array $arguments): string
    {
        return 'Write '.ServerPaths::normalize($this->labelArgument($arguments, 'path'));
    }

    protected function permissions(): array
    {
        return [Permissions::FileUpdate, Permissions::FileCreate];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $path = ServerPaths::normalize($this->string($arguments, 'path'));
        throw_if($path === '/', ToolException::class, 'A file path is required.');

        $content = $arguments['content'] ?? null;
        throw_unless(is_string($content), ToolException::class, 'The "content" argument must be a string.');

        throw_if(mb_strlen($content, '8bit') > self::MAX_BYTES, ToolException::class, 'The content is larger than 256 KB.');

        // Addresses in tool output are replaced by placeholders; writing those back would
        // overwrite the real values.
        if (OutputRedactor::containsPlaceholder($content)) {
            throw new ToolException(sprintf('The content still holds the %s or %s placeholder from redacted output. Ask the user to edit those values in the file manager instead.', OutputRedactor::IP, OutputRedactor::HOST));
        }

        $server = $this->server($context);
        $exists = $this->exists($server, $path);
        $this->requirePermission($context, $exists ? Permissions::FileUpdate : Permissions::FileCreate);

        $this->writer->write($server, $path, $content);

        $bytes = mb_strlen($content, '8bit');

        return new ToolResult(sprintf('%s %s (%d bytes).', $exists ? 'Updated' : 'Created', $path, $bytes), ['path' => $path, 'bytes' => $bytes, 'created' => ! $exists]);
    }

    /**
     * @throws DaemonConnectionException
     */
    private function exists(Server $server, string $path): bool
    {
        $name = ServerPaths::basename($path);

        try {
            $entries = $this->lister->list($server, ServerPaths::dirname($path));
        } catch (DaemonConnectionException $daemonConnectionException) {
            if ($daemonConnectionException->getStatusCode() === Response::HTTP_NOT_FOUND) {
                return false;
            }

            throw $daemonConnectionException;
        }

        foreach ($entries as $entry) {
            if (($entry['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
}
