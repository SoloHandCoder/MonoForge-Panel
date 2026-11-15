<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Server;

use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Assistant\Tools\OutputRedactor;
use Pterodactyl\Services\Assistant\Tools\SecretRedactor;
use Pterodactyl\Services\Assistant\Tools\ServerPaths;

/**
 * Changes specific lines of an existing file: each edit replaces one exact piece of text.
 * The edits are applied to the real file on the server, never to the masked copy the model
 * read, and they may not touch a line that holds a hidden value:
 *
 *  - every `find` occurs exactly once in the file, and no `find` or `replace` holds a mask;
 *  - the full lines a `find` touches hold nothing that masking would hide;
 *  - after the edits, the file still hides exactly the same values, in the same order.
 *
 * So the model can change the MOTD of a server.properties that holds rcon.password, but can
 * never move, copy or change the password.
 */
final class EditFileTool extends ServerTool
{
    public const int MAX_EDITS = 20;

    public const int MAX_BYTES = WriteFileTool::MAX_BYTES;

    public const string TOUCHES_HIDDEN = 'This edit touches a line with a hidden value; leave hidden values where they are, or ask the user to change that line in the file manager.';

    public const string MOVES_HIDDEN = 'This edit moves, copies or changes a hidden value; leave hidden values where they are, or ask the user to change them in the file manager.';

    public function __construct(
        private readonly ReadsFileContents $reader,
        private readonly WritesFileContents $writer,
    ) {}

    /**
     * The file with the edits applied, or a refusal. Public so it can be tested on its own.
     *
     * @param  list<array{find: string, replace: string}>  $edits
     *
     * @throws ToolException
     */
    public static function apply(string $original, array $edits): string
    {
        $before = SecretRedactor::hiddenValues($original);
        throw_if($before === null, ToolException::class, 'This file could not be checked for hidden values, so it cannot be edited here.');

        $content = $original;
        foreach ($edits as $number => $edit) {
            $label = sprintf('Edit %d', $number + 1);
            throw_unless(mb_substr_count($original, $edit['find']) === 1, ToolException::class, "{$label}: the text to find must occur exactly once in the file.");

            throw_unless(mb_substr_count($content, $edit['find']) === 1, ToolException::class, "{$label}: an earlier edit changed the text it finds; combine them.");

            // The full lines the match touches, in the file as it is now.
            $at = (int) mb_strpos($content, $edit['find']);
            $start = mb_strrpos(mb_substr($content, 0, $at), "\n");
            $start = $start === false ? 0 : $start + 1;
            $end = mb_strpos($content, "\n", $at + mb_strlen($edit['find']));
            $lines = mb_substr($content, $start, ($end === false ? mb_strlen($content) : $end) - $start);
            throw_if(SecretRedactor::hidesSomething($lines), ToolException::class, self::TOUCHES_HIDDEN);

            $content = mb_substr($content, 0, $at).$edit['replace'].mb_substr($content, $at + mb_strlen($edit['find']));

            // In a file with hidden values, an edited line may not open a string that runs on
            // into the following lines (a YAML block scalar, an unclosed quote, a heredoc), or
            // a hidden value below it would become part of that string, such as the MOTD.
            if ($before !== []) {
                $end = mb_strpos($content, "\n", $at + mb_strlen($edit['replace']));
                $edited = mb_substr($content, $start, ($end === false ? mb_strlen($content) : $end) - $start);
                throw_if(self::opensMultilineString($edited), ToolException::class, self::MOVES_HIDDEN);
            }
        }

        // Every hidden value of the original is still there, unchanged and in order, and no
        // new one appeared (a secret written by the model, or one copied or moved).
        throw_unless(SecretRedactor::hiddenValues($content) === $before, ToolException::class, self::MOVES_HIDDEN);

        return $content;
    }

    public function name(): string
    {
        return 'edit_file';
    }

    public function description(): string
    {
        return 'Change specific parts of an existing text file, such as one setting, without rewriting the whole file. Each edit replaces one piece of text ("find", which must occur exactly once in the file) with "replace". Use it for files that hold hidden values ('.SecretRedactor::PLACEHOLDER.'): lines with hidden values cannot be changed. The user is asked to confirm first.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'path' => ['type' => 'string', 'description' => 'The file to change, such as "/server.properties".'],
            'edits' => [
                'type' => 'array',
                'maxItems' => self::MAX_EDITS,
                'description' => 'The changes, applied in order.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'find' => ['type' => 'string', 'description' => 'The exact text to replace, such as "motd=Old". It must occur exactly once.'],
                        'replace' => ['type' => 'string', 'description' => 'The new text, such as "motd=New".'],
                    ],
                    'required' => ['find', 'replace'],
                    'additionalProperties' => false,
                ],
            ],
        ], ['path', 'edits']);
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return true;
    }

    public function prepareArguments(array $arguments, ToolContext $context): array
    {
        $path = ServerPaths::normalize($this->string($arguments, 'path'));
        throw_if($path === '/', ToolException::class, 'A file path is required.');

        return ['path' => $path, 'edits' => $this->edits($arguments)];
    }

    /**
     * The edits as find → replace pairs, only for a user who may read the file (the find
     * text shows what the file holds); otherwise just how many there are.
     */
    public function callDetails(array $arguments, ToolContext $context): array
    {
        $edits = is_array($arguments['edits'] ?? null) ? array_values($arguments['edits']) : [];
        $details = [['label' => 'Path', 'value' => ServerPaths::normalize($this->labelValue($arguments, 'path'))]];

        $server = $context->server;
        if (! $server instanceof Server || ! $context->user->can(Permissions::FileReadContent->value, $server)) {
            $details[] = ['label' => 'Changes', 'value' => sprintf('%d %s. You cannot read this file, so the changed text is not shown.', count($edits), count($edits) === 1 ? 'edit' : 'edits')];

            return $details;
        }

        foreach ($edits as $index => $edit) {
            $find = is_array($edit) && is_string($edit['find'] ?? null) ? $edit['find'] : '';
            $replace = is_array($edit) && is_string($edit['replace'] ?? null) ? $edit['replace'] : '';
            $details[] = [
                'label' => sprintf('Edit %d of %d', $index + 1, count($edits)),
                'value' => $this->prefixLines('- ', $find)."\n".$this->prefixLines('+ ', $replace),
            ];
        }

        return $details;
    }

    public function describeCall(array $arguments): string
    {
        $edits = is_array($arguments['edits'] ?? null) ? count($arguments['edits']) : 0;

        return sprintf('Edit %s (%d %s)', self::shorten(ServerPaths::normalize($this->labelValue($arguments, 'path')), 100), $edits, $edits === 1 ? 'change' : 'changes');
    }

    protected function permissions(): array
    {
        return [Permissions::FileUpdate];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $path = ServerPaths::normalize($this->string($arguments, 'path'));
        $edits = $this->edits($arguments);
        $server = $this->server($context);

        try {
            $original = $this->reader->read($server, $path, self::MAX_BYTES);
        } catch (FileSizeTooLargeException $fileSizeTooLargeException) {
            throw new ToolException('This file is larger than 256 KB, which is too large to edit here.', 0, $fileSizeTooLargeException);
        }

        throw_if(str_contains($original, "\0") || ! mb_check_encoding($original, 'UTF-8'), ToolException::class, 'This file is not a text file.');

        $content = self::apply($original, $edits);
        throw_if(mb_strlen($content, '8bit') > self::MAX_BYTES, ToolException::class, 'The edited file would be larger than 256 KB.');

        $this->writer->write($server, $path, $content);

        return new ToolResult(sprintf('Edited %s (%d %s).', $path, count($edits), count($edits) === 1 ? 'change' : 'changes'), ['path' => $path, 'edits' => count($edits)]);
    }

    /**
     * Whether any of the lines starts a string that continues on the next line: a YAML block
     * scalar (`key: |`), a quoted value left open at the end of the line, or a PHP heredoc.
     * Fails closed when a pattern cannot be checked.
     */
    private static function opensMultilineString(string $lines): bool
    {
        $patterns = [
            '/[:\-]\h*+[|>][+-]?\d?\h*+(?:#[^\n]*+)?\r?$/m',
            '/(?:^|[:=,\[{(]|=>)\h*+"(?:[^"\\\\\n]++|\\\\.)*+\r?$/m',
            '/(?:^|[:=,\[{(]|=>)\h*+\'(?:[^\'\\\\\n]++|\\\\.|\'\')*+\r?$/m',
            '/<<<\h*+[\'"]?\w/',
        ];

        foreach ($patterns as $pattern) {
            $matched = preg_match($pattern, $lines);
            if ($matched !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The validated edits.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<array{find: string, replace: string}>
     *
     * @throws ToolException
     */
    private function edits(array $arguments): array
    {
        $edits = $arguments['edits'] ?? null;
        throw_if(! is_array($edits) || $edits === [], ToolException::class, 'The "edits" argument must be a non-empty list of {find, replace} objects.');

        throw_if(count($edits) > self::MAX_EDITS, ToolException::class, sprintf('At most %d edits can be made at once.', self::MAX_EDITS));

        $valid = [];
        foreach (array_values($edits) as $number => $edit) {
            $find = is_array($edit) ? ($edit['find'] ?? null) : null;
            $replace = is_array($edit) ? ($edit['replace'] ?? null) : null;
            throw_if(! is_string($find) || $find === '' || ! is_string($replace), ToolException::class, sprintf('Edit %d needs a non-empty "find" and a "replace" text.', $number + 1));

            foreach ([$find, $replace] as $text) {
                throw_if(SecretRedactor::containsPlaceholder($text) || OutputRedactor::containsPlaceholder($text), ToolException::class, sprintf('Edit %d holds a hidden value or address placeholder. Leave hidden values where they are; the user can change them in the file manager.', $number + 1));
            }

            $valid[] = ['find' => $find, 'replace' => $replace];
        }

        return $valid;
    }

    private function prefixLines(string $prefix, string $text): string
    {
        return implode("\n", array_map(fn (string $line): string => $prefix.$line, explode("\n", $text)));
    }
}
