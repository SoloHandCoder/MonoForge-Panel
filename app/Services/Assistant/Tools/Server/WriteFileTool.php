<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Illuminate\Http\Response;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Assistant\Tools\OutputRedactor;
use Pterodactyl\Services\Assistant\Tools\SecretRedactor;
use Pterodactyl\Services\Assistant\Tools\ServerPaths;

/**
 * Creates or overwrites a text file. Like the file manager, overwriting needs file.update
 * and creating needs file.create.
 *
 * The model only ever reads files with secret values masked. When it writes such a file back,
 * each mask is replaced by the value it hid, taken from the file on the server, so it can edit
 * the rest of a config without learning (or wiping) its passwords.
 */
final class WriteFileTool extends ServerTool
{
    public const int MAX_BYTES = 262_144;

    /** How much of the new content the card shows before the user allows the write. */
    public const int PREVIEW_BYTES = 2048;

    public function __construct(
        private readonly WritesFileContents $writer,
        private readonly ListsDirectories $lister,
        private readonly ReadsFileContents $reader,
    ) {}

    public function name(): string
    {
        return 'write_file';
    }

    public function description(): string
    {
        return 'Create a text file or replace the whole contents of an existing one (at most 256 KB). The user is asked to confirm first. Always write the complete file, not only the changed part. Leave values shown as '.SecretRedactor::PLACEHOLDER.' as they are, on their own line: they keep their current value.';
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

    public function prepareArguments(array $arguments, ToolContext $context): array
    {
        $path = ServerPaths::normalize($this->string($arguments, 'path'));
        throw_if($path === '/', ToolException::class, 'A file path is required.');

        $content = $arguments['content'] ?? null;
        throw_unless(is_string($content), ToolException::class, 'The "content" argument must be a string.');

        return ['path' => $path, 'content' => $content];
    }

    public function callDetails(array $arguments): array
    {
        $content = is_string($arguments['content'] ?? null) ? $arguments['content'] : '';
        $bytes = mb_strlen($content, '8bit');
        $preview = mb_strcut($content, 0, self::PREVIEW_BYTES, 'UTF-8');
        $rest = $bytes - mb_strlen($preview, '8bit');

        return [
            ['label' => 'Path', 'value' => ServerPaths::normalize($this->labelValue($arguments, 'path'))],
            ['label' => 'Size', 'value' => sprintf('%s bytes', number_format($bytes))],
            ['label' => $rest > 0 ? sprintf('Content (first %s bytes)', number_format(mb_strlen($preview, '8bit'))) : 'Content', 'value' => $rest > 0 ? $preview.sprintf("\n… %s more bytes not shown", number_format($rest)) : $preview],
            ...(SecretRedactor::containsPlaceholder($content) ? [['label' => 'Hidden values', 'value' => sprintf('Each %s keeps its current value from the file on the server.', SecretRedactor::PLACEHOLDER)]] : []),
        ];
    }

    public function describeCall(array $arguments): string
    {
        return 'Write '.self::shorten(ServerPaths::normalize($this->labelValue($arguments, 'path')));
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

        if (SecretRedactor::containsPlaceholder($content)) {
            $content = $this->restoreSecrets($server, $path, $content, $exists);
        }

        $this->writer->write($server, $path, $content);

        $bytes = mb_strlen($content, '8bit');

        return new ToolResult(sprintf('%s %s (%d bytes).', $exists ? 'Updated' : 'Created', $path, $bytes), ['path' => $path, 'bytes' => $bytes, 'created' => ! $exists]);
    }

    /**
     * The content with each masked value put back from the file on the server.
     *
     * @throws ToolException when a mask has no value to restore
     * @throws DaemonConnectionException
     */
    private function restoreSecrets(Server $server, string $path, string $content, bool $exists): string
    {
        $refuse = sprintf('The content holds %s where the file on the server has no matching hidden value. Keep each %1$s on the line it was read on, or ask the user to edit that value in the file manager.', SecretRedactor::PLACEHOLDER);
        throw_unless($exists, ToolException::class, $refuse);

        try {
            $original = $this->reader->read($server, $path, self::MAX_BYTES);
        } catch (FileSizeTooLargeException $fileSizeTooLargeException) {
            throw new ToolException($refuse, 0, $fileSizeTooLargeException);
        }

        return SecretRedactor::restore($content, $original) ?? throw new ToolException($refuse);
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
