<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Contracts\Assistant\AssistantTool;
use Pterodactyl\Contracts\Assistant\AuthorizesAssistantTool;
use Pterodactyl\Contracts\Assistant\PreparesToolCall;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Activity\ActivityLogTargetableService;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The shared frame of every assistant tool. execute() reloads the user (and server), checks
 * the permission again, runs the tool, turns known failures into user-safe ToolExceptions,
 * takes IPs and node host names out of the output and caps its size.
 */
abstract class AbstractTool implements AssistantTool, AuthorizesAssistantTool, PreparesToolCall
{
    public const string SCOPE_SERVER = 'server';

    public const string SCOPE_ADMIN = 'admin';

    /** The most output a tool hands back; the conversation stores at most 16 KB of it. */
    public const int MAX_CONTENT_BYTES = 15_000;

    public const string DENIED = 'You do not have permission to do this.';

    /** The longest label; the details under it always show the full arguments. */
    public const int LABEL_LENGTH = 120;

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @throws ToolException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws DisplayException
     * @throws HttpExceptionInterface
     */
    abstract protected function run(array $arguments, ToolContext $context): ToolResult;

    /**
     * Cut text to at most $bytes bytes on a character boundary, noting how much was left out.
     */
    public static function truncate(string $text, int $bytes = self::MAX_CONTENT_BYTES): string
    {
        $length = mb_strlen($text, '8bit');
        if ($length <= $bytes) {
            return $text;
        }

        $cut = mb_strcut($text, 0, $bytes, 'UTF-8');

        return $cut.sprintf("\n[output truncated: %d more bytes not shown]", $length - mb_strlen($cut, '8bit'));
    }

    /**
     * A value as the details show it: text as it is, a list one entry a line, the rest as JSON.
     */
    public static function detailValue(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            $value === null => '',
            is_array($value) && array_is_list($value) && array_filter($value, is_string(...)) === $value => implode("\n", $value),
            default => (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        };
    }

    /**
     * Text for a one-line label: whitespace collapsed, cut with an ellipsis when long. The
     * details under the label always show the full value.
     */
    public static function shorten(string $value, int $length = self::LABEL_LENGTH): string
    {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1).'…' : $value;
    }

    /**
     * Auto tools run straight away. Tools that change something override this.
     */
    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return false;
    }

    /**
     * Arguments are stored as the model sent them unless a tool settles them first.
     */
    public function prepareArguments(array $arguments, ToolContext $context): array
    {
        return $arguments;
    }

    /**
     * Each argument in full, lists one entry a line and anything else as JSON.
     */
    public function callDetails(array $arguments, ToolContext $context): array
    {
        $details = [];
        foreach ($arguments as $key => $value) {
            $details[] = ['label' => (string) $key, 'value' => self::detailValue($value)];
        }

        return $details;
    }

    final public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $context = $this->refresh($context);
        throw_unless($this->allowed($context), ToolException::class, self::DENIED);

        $redactor = new OutputRedactor;
        $keep = $this->keep($context);

        // Activity logged by the actions a tool calls belongs to the requesting user, also
        // when the tool runs in a queued job without a signed-in user.
        App::make(ActivityLogTargetableService::class)->setActor($context->user);

        try {
            $this->guard($context);
            $result = $this->run($arguments, $context);
        } catch (ToolException $toolException) {
            throw new ToolException($redactor->redact($toolException->getMessage(), $keep), 0, $toolException);
        } catch (ValidationException $validationException) {
            throw new ToolException($redactor->redact($validationException->validator->errors()->first(), $keep), 0, $validationException);
        } catch (AuthorizationException $authorizationException) {
            throw new ToolException(self::DENIED, 0, $authorizationException);
        } catch (DisplayException|HttpExceptionInterface $exception) {
            $message = $exception->getMessage() !== '' ? $exception->getMessage() : 'The request could not be completed.';

            throw new ToolException($redactor->redact($message, $keep), 0, $exception);
        }

        /** @var array<string, mixed> $display */
        $display = $redactor->redactValue($result->display, $keep);

        $private = $result->private === null ? null : self::truncate($redactor->redact($result->private, $keep));

        return new ToolResult(self::truncate($redactor->redact($result->content, $keep)), $display, $private);
    }

    /**
     * The context with the user (and server) read again, so a revoked permission or a
     * suspension since the conversation started is seen.
     *
     * @throws ToolException
     */
    protected function refresh(ToolContext $context): ToolContext
    {
        $user = User::query()->find($context->user->id) ?? throw new ToolException(self::DENIED);

        return new ToolContext($user, $context->scope, $context->server);
    }

    /**
     * Checks that must pass before the tool runs, beyond the permission.
     *
     * @throws ToolException
     * @throws HttpExceptionInterface
     */
    protected function guard(ToolContext $context): void {}

    /**
     * Values left in the output even though they look like addresses.
     *
     * @return list<string>
     */
    protected function keep(ToolContext $context): array
    {
        return [];
    }

    /**
     * A JSON schema for an object of the given properties.
     *
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    protected function schema(array $properties = [], array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => $properties === [] ? (object) [] : $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    protected function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * A required string argument, or an optional one when $default is given.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws ToolException
     */
    protected function string(array $arguments, string $key, ?string $default = null, int $max = 2048): string
    {
        $value = $arguments[$key] ?? null;
        if ($value === null || $value === '') {
            return $default ?? throw new ToolException("The \"{$key}\" argument is required.");
        }

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        throw_unless(is_string($value), ToolException::class, "The \"{$key}\" argument must be a string.");

        throw_if(mb_strlen($value) > $max, ToolException::class, "The \"{$key}\" argument must be at most {$max} characters.");

        return $value;
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @throws ToolException
     */
    protected function integer(array $arguments, string $key, int $default, int $min, int $max): int
    {
        $value = $arguments[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', mb_trim($value)) === 1) {
            $value = (int) mb_trim($value);
        }

        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        throw_unless(is_int($value), ToolException::class, "The \"{$key}\" argument must be a whole number.");

        return max($min, min($max, $value));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function boolean(array $arguments, string $key, bool $default): bool
    {
        $value = $arguments[$key] ?? null;

        return is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default);
    }

    /**
     * A non-empty list of strings.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<string>
     *
     * @throws ToolException
     */
    protected function stringList(array $arguments, string $key, int $maxItems, int $maxLength = 2048): array
    {
        $value = $arguments[$key] ?? null;
        if (is_string($value) && $value !== '') {
            $value = [$value];
        }

        throw_if(! is_array($value) || $value === [], ToolException::class, "The \"{$key}\" argument must be a non-empty list of strings.");

        throw_if(count($value) > $maxItems, ToolException::class, "The \"{$key}\" argument can hold at most {$maxItems} entries.");

        $items = [];
        foreach ($value as $item) {
            throw_if(! is_string($item) || $item === '' || mb_strlen($item) > $maxLength, ToolException::class, "Every entry of \"{$key}\" must be a non-empty string of at most {$maxLength} characters.");

            $items[] = $item;
        }

        return $items;
    }

    /**
     * An argument as text for a call label, in full: lists are joined with commas. Pass the
     * result (after any normalising) through shorten() for the label.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function labelValue(array $arguments, string $key, string $fallback = ''): string
    {
        $value = $arguments[$key] ?? null;
        if (is_array($value)) {
            $value = implode(', ', array_filter($value, is_string(...)));
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $fallback;
    }

    /**
     * A short value for a call label.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function labelArgument(array $arguments, string $key, string $fallback = ''): string
    {
        return self::shorten($this->labelValue($arguments, $key, $fallback));
    }
}
