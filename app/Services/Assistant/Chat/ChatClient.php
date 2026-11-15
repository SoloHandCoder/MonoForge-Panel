<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Chat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Pterodactyl\Exceptions\Assistant\ChatProviderException;
use Pterodactyl\Models\Assistant\AiProvider;

/**
 * Talks to any OpenAI-compatible chat completions API (Cline, ClinePass, Ollama, OpenRouter,
 * DeepSeek, OpenAI, ...). Quirks it handles:
 *
 *  - Cline streams by default, so `stream:false` is always sent, and answers in a legacy
 *    `{success, data}` wrapper unless asked for `X-Response-Format: openai` (a wrapper that
 *    still arrives is unwrapped). A provider that streams anyway is read as SSE.
 *  - Errors can arrive with HTTP 200 and `finish_reason:"error"`.
 *  - Ollama ignores `tool_choice`, so it is never sent; its tool calls may lack ids (one is
 *    generated) and carry their arguments as an object instead of a JSON string.
 *  - Usage comes as prompt_tokens/completion_tokens, prompt_eval_count/eval_count or
 *    input_tokens/output_tokens; when none is reported it is estimated at four characters a token.
 *  - 429 and 5xx are retried twice with backoff; each attempt times out after 90 seconds.
 */
class ChatClient
{
    public const int TIMEOUT_SECONDS = 90;

    public const int MAX_RETRIES = 2;

    /** The longest Retry-After the client waits for, in seconds. */
    public const int MAX_RETRY_AFTER = 10;

    /**
     * The longest one complete() call can take, in seconds: every attempt timing out, with
     * the longest wait between attempts.
     */
    public static function maxCallSeconds(): int
    {
        return (self::MAX_RETRIES + 1) * self::TIMEOUT_SECONDS + self::MAX_RETRIES * self::MAX_RETRY_AFTER;
    }

    /** Four characters a token: a rough estimate that errs on the high side for code. */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Sends the conversation and returns the model's answer.
     *
     * @param  list<array<string, mixed>>  $messages  OpenAI chat messages
     * @param  list<array<string, mixed>>  $tools  OpenAI function tool definitions
     *
     * @throws ChatProviderException
     */
    public function complete(AiProvider $provider, array $messages, array $tools = [], int $maxTokens = 2048): ChatResponse
    {
        throw_unless(ProviderUrl::isAllowed($provider->base_url), ChatProviderException::class, 'The AI provider address is not allowed.');

        $payload = [
            'model' => $provider->model,
            'messages' => $messages,
            'stream' => false,
            'max_tokens' => $maxTokens,
        ];

        // Never tool_choice: Ollama does not support it, and "auto" is everyone's default.
        if ($tools !== [] && $provider->supports_tools) {
            $payload['tools'] = $tools;
        }

        $response = $this->send($provider, $payload);

        return $this->parse($response, $messages);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(AiProvider $provider, array $payload): Response
    {
        $url = ProviderUrl::completions($provider->base_url);

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->request($provider)->post($url, $payload);
            } catch (ConnectionException) {
                throw new ChatProviderException('Could not reach the AI provider in time. Check that it is running and its address is right.');
            }

            if ($response->successful()) {
                return $response;
            }

            // A reasoning model can spend the whole max_tokens budget thinking and answer
            // nothing; Cline then refuses with "empty response content". Ask once more
            // without the limit instead of failing the turn.
            if (isset($payload['max_tokens']) && $this->isEmptyResponseError($response)) {
                unset($payload['max_tokens']);

                continue;
            }

            $status = $response->status();
            if (($status === 429 || $status >= 500) && $attempt < self::MAX_RETRIES) {
                Sleep::for($this->backoff($response, $attempt))->milliseconds();

                continue;
            }

            throw new ChatProviderException($this->errorMessage($response));
        }
    }

    private function request(AiProvider $provider): PendingRequest
    {
        $headers = $provider->preset->headers();
        foreach ($provider->extra_headers ?? [] as $name => $value) {
            if (is_string($name) && is_scalar($value) && mb_strtolower($name) !== 'authorization') {
                $headers[$name] = (string) $value;
            }
        }

        $request = Http::acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(10)
            ->withoutRedirecting()
            ->withHeaders($headers);

        return $provider->hasKey() ? $request->withToken((string) $provider->api_key) : $request;
    }

    private function isEmptyResponseError(Response $response): bool
    {
        $error = $response->json('error');
        $message = is_array($error) ? ($error['message'] ?? null) : $error;

        return is_string($message) && str_contains(mb_strtolower($message), 'empty response');
    }

    private function backoff(Response $response, int $attempt): int
    {
        $retryAfter = $response->header('Retry-After');
        if (is_numeric($retryAfter)) {
            return (int) (min(self::MAX_RETRY_AFTER, max(0, (float) $retryAfter)) * 1000);
        }

        return 1000 * (2 ** $attempt);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function parse(Response $response, array $messages): ChatResponse
    {
        $body = $response->body();
        $json = json_decode($body, true);
        if (! is_array($json)) {
            $json = $this->parseStream($body);
        }

        // Cline's legacy wrapper: {"success": true, "data": {...completion...}}.
        if (array_key_exists('success', $json) && isset($json['data']) && is_array($json['data'])) {
            if ($json['success'] === false) {
                throw new ChatProviderException($this->providerError($json['data']['error'] ?? $json['error'] ?? null) ?? 'The AI provider returned an error.');
            }

            $json = $json['data'];
        }

        $choices = $json['choices'] ?? null;
        $choice = is_array($choices) ? ($choices[0] ?? null) : null;
        if (! is_array($choice)) {
            // Ollama's native /api/chat answer, should a base URL point there.
            $choice = isset($json['message']) && is_array($json['message'])
                ? ['message' => $json['message'], 'finish_reason' => $json['done_reason'] ?? null]
                : null;
        }

        if (! is_array($choice)) {
            throw new ChatProviderException($this->providerError($json['error'] ?? null) ?? 'The AI provider returned an answer the panel could not read.');
        }

        $finish = is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null;
        if ($finish === 'error') {
            throw new ChatProviderException($this->providerError($choice['error'] ?? $json['error'] ?? null) ?? 'The AI provider stopped with an error.');
        }

        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $content = $this->content($message['content'] ?? null);
        $toolCalls = $this->toolCalls($message['tool_calls'] ?? null);

        $usage = $this->usage($json);
        if (! $usage instanceof ChatUsage) {
            $output = mb_strlen($content ?? '');
            foreach ($toolCalls as $call) {
                $output += mb_strlen($call->name) + mb_strlen($call->rawArguments);
            }

            $usage = new ChatUsage(
                input: self::estimateTokens((string) json_encode($messages)),
                output: (int) ceil($output / 4),
                reported: false,
            );
        }

        return new ChatResponse($content, $toolCalls, $finish, $usage);
    }

    private function content(mixed $content): ?string
    {
        if (is_string($content)) {
            return $content === '' ? null : $content;
        }

        // Some providers send content parts: [{"type": "text", "text": "..."}].
        if (is_array($content)) {
            $text = '';
            foreach ($content as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }

            return $text === '' ? null : $text;
        }

        return null;
    }

    /**
     * @return list<ChatToolCall>
     */
    private function toolCalls(mixed $calls): array
    {
        if (! is_array($calls)) {
            return [];
        }

        $result = [];
        foreach ($calls as $call) {
            $function = is_array($call) && is_array($call['function'] ?? null) ? $call['function'] : null;
            $name = is_array($function) && is_string($function['name'] ?? null) ? mb_trim($function['name']) : '';
            if (! is_array($function) || $name === '') {
                continue;
            }

            $id = is_string($call['id'] ?? null) && $call['id'] !== '' ? $call['id'] : 'call_'.Str::random(24);
            $raw = $function['arguments'] ?? null;

            if (is_array($raw)) {
                // Ollama: the arguments are an object already.
                $arguments = $raw;
                $raw = (string) json_encode($raw === [] ? (object) [] : $raw);
            } elseif (is_string($raw)) {
                $decoded = mb_trim($raw) === '' ? [] : json_decode($raw, true);
                $arguments = is_array($decoded) ? $decoded : ($decoded === null && mb_trim($raw) === 'null' ? [] : null);
            } else {
                $arguments = [];
                $raw = '{}';
            }

            /** @var array<string, mixed>|null $arguments */
            $result[] = new ChatToolCall($id, $name, $arguments, $raw);
        }

        return $result;
    }

    /**
     * @param  array<mixed>  $json
     */
    private function usage(array $json): ?ChatUsage
    {
        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : $json;

        $input = $this->firstInt($usage, ['prompt_tokens', 'input_tokens', 'prompt_eval_count']);
        $output = $this->firstInt($usage, ['completion_tokens', 'output_tokens', 'eval_count']);
        if ($input === null && $output === null) {
            return null;
        }

        $details = is_array($usage['prompt_tokens_details'] ?? null) ? $usage['prompt_tokens_details'] : [];
        $cached = $this->firstInt($details, ['cached_tokens']) ?? $this->firstInt($usage, ['prompt_cache_hit_tokens', 'cache_read_input_tokens']) ?? 0;
        $cost = $usage['cost'] ?? null;

        return new ChatUsage(
            input: $input ?? 0,
            output: $output ?? 0,
            cached: $cached,
            cost: is_numeric($cost) ? (float) $cost : null,
        );
    }

    /**
     * @param  array<mixed>  $values
     * @param  list<string>  $keys
     */
    private function firstInt(array $values, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($values[$key]) && is_numeric($values[$key])) {
                return max(0, (int) $values[$key]);
            }
        }

        return null;
    }

    /**
     * Rebuilds a completion from a server-sent event stream, for a provider that streams even
     * though `stream:false` was sent. Comment lines (": OPENROUTER PROCESSING") are skipped.
     *
     * @return array<mixed>
     */
    private function parseStream(string $body): array
    {
        $content = '';
        $finish = null;
        $usage = null;
        $error = null;
        /** @var array<int, array{id: string|null, name: string, arguments: string}> $calls */
        $calls = [];
        $seen = false;

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = mb_trim($line);
            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $data = mb_trim(mb_substr($line, 5));
            if ($data === '[DONE]') {
                break;
            }

            $chunk = json_decode($data, true);
            if (! is_array($chunk)) {
                continue;
            }

            $seen = true;
            if (isset($chunk['error'])) {
                $error = $chunk['error'];
            }

            if (is_array($chunk['usage'] ?? null)) {
                $usage = $chunk['usage'];
            }

            $choices = $chunk['choices'] ?? null;
            $choice = is_array($choices) ? ($choices[0] ?? null) : null;
            if (! is_array($choice)) {
                continue;
            }

            if (is_string($choice['finish_reason'] ?? null)) {
                $finish = $choice['finish_reason'];
                $error = $choice['error'] ?? $error;
            }

            $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
            if (is_string($delta['content'] ?? null)) {
                $content .= $delta['content'];
            }

            foreach (is_array($delta['tool_calls'] ?? null) ? $delta['tool_calls'] : [] as $position => $part) {
                if (! is_array($part)) {
                    continue;
                }

                $index = is_int($part['index'] ?? null) ? $part['index'] : (int) $position;
                $calls[$index] ??= ['id' => null, 'name' => '', 'arguments' => ''];
                if (is_string($part['id'] ?? null) && $part['id'] !== '') {
                    $calls[$index]['id'] = $part['id'];
                }

                $function = is_array($part['function'] ?? null) ? $part['function'] : [];
                if (is_string($function['name'] ?? null)) {
                    $calls[$index]['name'] .= $function['name'];
                }

                if (is_string($function['arguments'] ?? null)) {
                    $calls[$index]['arguments'] .= $function['arguments'];
                }
            }
        }

        throw_unless($seen, ChatProviderException::class, 'The AI provider returned an answer the panel could not read.');

        if ($error !== null && $finish === null) {
            $finish = 'error';
        }

        ksort($calls);
        $toolCalls = array_map(fn (array $call): array => [
            'id' => $call['id'],
            'type' => 'function',
            'function' => ['name' => $call['name'], 'arguments' => $call['arguments']],
        ], array_values($calls));

        return array_filter([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => $content, 'tool_calls' => $toolCalls],
                'finish_reason' => $finish,
                'error' => $error,
            ]],
            'usage' => $usage,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function errorMessage(Response $response): string
    {
        $status = $response->status();
        $json = $response->json();
        $detail = $this->providerError(is_array($json) ? ($json['error'] ?? $json['message'] ?? null) : null);

        $message = match (true) {
            $status === 401, $status === 403 => "The AI provider rejected the API key (HTTP {$status}).",
            $status === 402 => 'The AI provider account has no credits left (HTTP 402).',
            $status === 404 => 'The AI provider does not know this model or address (HTTP 404).',
            $status === 429 => 'The AI provider is rate limiting requests. Try again in a moment.',
            $status >= 500 => "The AI provider had an error (HTTP {$status}). Try again later.",
            default => "The AI provider refused the request (HTTP {$status}).",
        };

        return $detail === null ? $message : $message.' '.$detail;
    }

    /**
     * The provider's own error message, shortened and with anything that looks like a key
     * removed, or null when there is none.
     */
    private function providerError(mixed $error): ?string
    {
        $message = match (true) {
            is_string($error) => $error,
            is_array($error) && is_string($error['message'] ?? null) => $error['message'],
            is_array($error) && is_string($error['code'] ?? null) => $error['code'],
            default => null,
        };

        if ($message === null || mb_trim($message) === '') {
            return null;
        }

        $message = (string) preg_replace('/\b(sk|pk|key|ak|rk)[-_][A-Za-z0-9_\-*]{6,}|\b[A-Za-z0-9_\-]{32,}\b/', '[redacted]', mb_trim($message));

        return Str::limit($message, 200);
    }
}
