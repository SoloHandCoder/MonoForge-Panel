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
use Throwable;

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

    /** How many changed lines the card lists. */
    public const int CHANGED_LINES = 40;

    public const string HIDDEN_VALUES_NOTE = 'Each '.SecretRedactor::PLACEHOLDER.' is replaced by the value it hides in the current file on the server, at the same key. If a hidden value was moved or changed, the write is refused and nothing is written.';

    public function __construct(
        private readonly WritesFileContents $writer,
        private readonly ListsDirectories $lister,
        private readonly ReadsFileContents $reader,
    ) {}

    /**
     * A short summary of the lines that differ between two texts: the changed block between
     * their common start and end, with its removed and added lines.
     *
     * @param  list<string>  $old
     * @param  list<string>  $new
     */
    public static function lineDiff(array $old, array $new): string
    {
        $start = 0;
        while ($start < count($old) && $start < count($new) && $old[$start] === $new[$start]) {
            $start++;
        }

        $oldEnd = count($old);
        $newEnd = count($new);
        while ($oldEnd > $start && $newEnd > $start && $old[$oldEnd - 1] === $new[$newEnd - 1]) {
            $oldEnd--;
            $newEnd--;
        }

        if ($oldEnd === $start && $newEnd === $start) {
            return 'No lines change.';
        }

        $removed = array_slice($old, $start, $oldEnd - $start);
        $added = array_slice($new, $start, $newEnd - $start);
        $lines = [
            ...array_map(fn (string $line): string => '- '.$line, $removed),
            ...array_map(fn (string $line): string => '+ '.$line, $added),
        ];
        $shown = array_slice($lines, 0, self::CHANGED_LINES);
        $more = count($lines) - count($shown);

        return sprintf('Lines %d-%d change: %d removed, %d added.', $start + 1, max($start + 1, $oldEnd), count($removed), count($added))
            ."\n".implode("\n", $shown)
            .($more > 0 ? sprintf("\n… %d more changed lines not shown", $more) : '');
    }

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

    public function callDetails(array $arguments, ToolContext $context): array
    {
        $content = is_string($arguments['content'] ?? null) ? $arguments['content'] : '';
        $bytes = mb_strlen($content, '8bit');
        $preview = mb_strcut($content, 0, self::PREVIEW_BYTES, 'UTF-8');
        $rest = $bytes - mb_strlen($preview, '8bit');

        return [
            ['label' => 'Path', 'value' => ServerPaths::normalize($this->labelValue($arguments, 'path'))],
            ['label' => 'Size', 'value' => sprintf('%s bytes', number_format($bytes))],
            ['label' => $rest > 0 ? sprintf('Content (first %s bytes)', number_format(mb_strlen($preview, '8bit'))) : 'Content', 'value' => $rest > 0 ? $preview.sprintf("\n… %s more bytes not shown", number_format($rest)) : $preview],
            ['label' => 'Changes', 'value' => $this->changes($arguments, $context)],
            ...(SecretRedactor::containsPlaceholder($content) ? [['label' => 'Hidden values', 'value' => self::HIDDEN_VALUES_NOTE]] : []),
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
        $refuse = SecretRedactor::MOVED;
        throw_unless($exists, ToolException::class, $refuse);

        try {
            $original = $this->reader->read($server, $path, self::MAX_BYTES);
        } catch (FileSizeTooLargeException $fileSizeTooLargeException) {
            throw new ToolException($refuse, 0, $fileSizeTooLargeException);
        }

        return SecretRedactor::restore($content, $original) ?? throw new ToolException(SecretRedactor::MOVED);
    }

    /**
     * Which lines of the current file the write changes, compared on masked copies (so no
     * secret reaches the card) with addresses taken out.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function changes(array $arguments, ToolContext $context): string
    {
        $server = $context->server;
        $content = is_string($arguments['content'] ?? null) ? $arguments['content'] : '';
        $path = ServerPaths::normalize($this->labelValue($arguments, 'path'));
        if (! $server instanceof Server) {
            return 'Could not compare with the current file.';
        }

        try {
            $current = $this->exists($server, $path) ? $this->reader->read($server, $path, self::MAX_BYTES) : null;
        } catch (Throwable) {
            return 'Could not compare with the current file.';
        }

        $new = explode("\n", str_replace("\r\n", "\n", $content));
        if ($current === null) {
            return sprintf('A new file with %s lines.', number_format(count($new)));
        }

        return (new OutputRedactor)->redact(self::lineDiff(explode("\n", str_replace("\r\n", "\n", SecretRedactor::redact($current))), $new));
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
