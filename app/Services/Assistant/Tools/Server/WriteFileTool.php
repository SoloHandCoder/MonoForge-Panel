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
 * The model only ever reads files with secret values masked, so a full overwrite could wipe
 * or move them. write_file therefore refuses content holding a mask, and refuses to overwrite
 * a file that holds values masking would hide: such files are changed line by line with
 * edit_file instead.
 */
final class WriteFileTool extends ServerTool
{
    public const int MAX_BYTES = 262_144;

    /** How much of the new content the card shows before the user allows the write. */
    public const int PREVIEW_BYTES = 2048;

    /** How many changed lines the card lists. */
    public const int CHANGED_LINES = 40;

    public const string HAS_HIDDEN_VALUES = 'This file contains hidden values; use edit_file to change specific lines.';

    public const string HOLDS_MASK = 'The content holds a hidden value ('.SecretRedactor::PLACEHOLDER.'), which would overwrite the real one. Use edit_file to change specific lines of a file with hidden values.';

    public const string NOT_READABLE = 'You cannot read this file, so its changes are not shown.';

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
        return 'Create a text file or replace the whole contents of an existing one (at most 256 KB). The user is asked to confirm first. Always write the complete file, not only the changed part. A file that holds hidden values (shown to you as '.SecretRedactor::PLACEHOLDER.') cannot be overwritten: change its lines with edit_file.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'path' => ['type' => 'string', 'description' => 'The file to write, such as "/config/paper-global.yml".'],
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

        throw_if(SecretRedactor::containsPlaceholder($content), ToolException::class, self::HOLDS_MASK);

        // Tell the model right away when the file holds hidden values; run() checks again.
        $server = $context->server;
        if ($server instanceof Server) {
            try {
                $current = $this->current($server, $path);
            } catch (Throwable) {
                $current = null;
            }

            throw_if($current !== null && SecretRedactor::hidesSomething($current), ToolException::class, self::HAS_HIDDEN_VALUES);
        }

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

        // Addresses and secrets in tool output are replaced by placeholders; writing those back
        // would overwrite the real values.
        if (OutputRedactor::containsPlaceholder($content)) {
            throw new ToolException(sprintf('The content still holds the %s or %s placeholder from redacted output. Ask the user to edit those values in the file manager instead.', OutputRedactor::IP, OutputRedactor::HOST));
        }

        throw_if(SecretRedactor::containsPlaceholder($content), ToolException::class, self::HOLDS_MASK);

        $server = $this->server($context);
        $exists = $this->exists($server, $path);
        $this->requirePermission($context, $exists ? Permissions::FileUpdate : Permissions::FileCreate);

        if ($exists) {
            try {
                $current = $this->reader->read($server, $path, self::MAX_BYTES);
            } catch (FileSizeTooLargeException $fileSizeTooLargeException) {
                throw new ToolException('The file on the server is larger than 256 KB, so it cannot be checked for hidden values or overwritten here.', 0, $fileSizeTooLargeException);
            }

            throw_if(SecretRedactor::hidesSomething($current), ToolException::class, self::HAS_HIDDEN_VALUES);
        }

        $this->writer->write($server, $path, $content);

        $bytes = mb_strlen($content, '8bit');

        return new ToolResult(sprintf('%s %s (%d bytes).', $exists ? 'Updated' : 'Created', $path, $bytes), ['path' => $path, 'bytes' => $bytes, 'created' => ! $exists]);
    }

    /**
     * Which lines of the current file the write changes, only for a user who may read the
     * file. The current lines are masked and have addresses taken out; the new lines are the
     * model's, shown as they are, so a changed host is visible.
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

        if (! $context->user->can(Permissions::FileReadContent->value, $server)) {
            return self::NOT_READABLE;
        }

        try {
            $current = $this->current($server, $path);
        } catch (Throwable) {
            return 'Could not compare with the current file.';
        }

        $new = explode("\n", str_replace("\r\n", "\n", $content));
        if ($current === null) {
            return sprintf('A new file with %s lines.', number_format(count($new)));
        }

        $old = explode("\n", (new OutputRedactor)->redact(str_replace("\r\n", "\n", SecretRedactor::redact($current))));

        return self::lineDiff($old, $new);
    }

    /**
     * The current contents of the file, or null when there is none.
     *
     * @throws DaemonConnectionException
     * @throws FileSizeTooLargeException
     */
    private function current(Server $server, string $path): ?string
    {
        return $this->exists($server, $path) ? $this->reader->read($server, $path, self::MAX_BYTES) : null;
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
