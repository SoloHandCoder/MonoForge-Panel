<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant;

use Illuminate\Support\Collection;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiMessage;
use Pterodactyl\Models\Assistant\AiProvider;
use Pterodactyl\Services\Assistant\Chat\ChatClient;

/**
 * Builds what the model sees: the system prompt, a rolling summary of older messages and
 * the recent messages. When the history grows past `context_tokens` (estimated at four
 * characters a token), the oldest turns are summarised into the conversation's summary
 * with one model call and only the recent ones are sent again.
 */
class ConversationContext
{
    /** Tool results are cut to 4 KB in the context. */
    public const int TOOL_RESULT_BYTES = 4096;

    /** What an older tool result becomes when a long turn outgrows the context. */
    public const string OMITTED = '[This older tool result was left out to stay within the context limit. Call the tool again if you still need it.]';

    /** How much of each message the summariser reads. */
    private const int SUMMARY_MESSAGE_CHARS = 2000;

    private const int SUMMARY_MAX_TOKENS = 512;

    public function __construct(private readonly ChatClient $client, private readonly AssistantBilling $billing) {}

    /** Cuts text to at most $bytes bytes without splitting a character. */
    public static function truncate(string $text, int $bytes): string
    {
        if (mb_strlen($text, '8bit') <= $bytes) {
            return $text;
        }

        return mb_strcut($text, 0, max(0, $bytes - 64))."\n[... truncated, ".mb_strlen($text, '8bit').' bytes in total]';
    }

    /**
     * The estimated tokens of chat messages.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    public static function estimateMessages(array $messages): int
    {
        return ChatClient::estimateTokens((string) json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function build(AiConversation $conversation, ?string $instruction = null): array
    {
        $this->summarizeIfNeeded($conversation);
        $system = SystemPrompt::for($conversation).($instruction === null ? '' : "\n\n".$instruction);

        return $this->compact([
            ['role' => 'system', 'content' => $system],
            ...$this->format($this->history($conversation)),
        ]);
    }

    /**
     * Whether the messages fit in `context_tokens`.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    public function fits(array $messages): bool
    {
        return self::estimateMessages($messages) <= AssistantSettings::contextTokens();
    }

    /**
     * Inside one long turn there is no earlier user message to summarise up to, so tool results
     * and tool call arguments (a file the model wrote, say) are left out of the context, oldest
     * first, until it fits. The newest assistant message and its results are kept as they are;
     * when they alone are too large the turn stops (see fits()).
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public function compact(array $messages): array
    {
        if ($this->fits($messages)) {
            return $messages;
        }

        $lastAssistant = null;
        foreach ($messages as $index => $message) {
            if (($message['role'] ?? null) === 'assistant') {
                $lastAssistant = $index;
            }
        }

        foreach ($messages as $index => $message) {
            if ($lastAssistant === null || $index >= $lastAssistant) {
                break;
            }

            if (($message['role'] ?? null) === 'tool' && ($message['content'] ?? '') !== self::OMITTED) {
                $messages[$index]['content'] = self::OMITTED;
            } elseif (($message['role'] ?? null) === 'assistant' && is_array($message['tool_calls'] ?? null)) {
                $messages[$index]['tool_calls'] = array_map(function (mixed $call): mixed {
                    if (is_array($call) && is_array($call['function'] ?? null)) {
                        $call['function']['arguments'] = '{}';
                    }

                    return $call;
                }, $message['tool_calls']);
            } else {
                continue;
            }

            if ($this->fits($messages)) {
                break;
            }
        }

        return $messages;
    }

    /**
     * Summarises the oldest turns when the context would be too large. Returns whether it did.
     */
    public function summarizeIfNeeded(AiConversation $conversation): bool
    {
        $history = $this->history($conversation);
        $limit = AssistantSettings::contextTokens();
        $system = ChatClient::estimateTokens(SystemPrompt::for($conversation));

        $messages = $history->values()->all();
        $sizes = array_map($this->estimate(...), $messages);
        if ($system + array_sum($sizes) <= $limit) {
            return false;
        }

        // Keep the newest turns that fit in half the budget, cutting only before a user message
        // so an assistant message is never separated from its tool results.
        $budget = intdiv($limit, 2);
        $cut = null;
        $kept = 0;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $kept += $sizes[$i];
            if ($messages[$i]->role !== AiMessage::ROLE_USER) {
                continue;
            }

            if ($cut === null || $kept <= $budget) {
                $cut = $i;
            }

            if ($kept > $budget) {
                break;
            }
        }

        if ($cut === null || $cut === 0) {
            return false;
        }

        $old = $history->slice(0, $cut)->values();
        $last = $old->last();
        if (! $last instanceof AiMessage) {
            return false;
        }

        $transcript = $old->map(fn (AiMessage $message): string => $this->transcriptLine($message))->implode("\n");
        $previous = is_string($conversation->summary) && $conversation->summary !== '' ? "Summary so far:\n{$conversation->summary}\n\n" : '';

        $response = $this->client->complete($this->providerOf($conversation), [
            ['role' => 'system', 'content' => SystemPrompt::summarizer()],
            ['role' => 'user', 'content' => $previous."Conversation to add to the summary:\n".$transcript],
        ], [], self::SUMMARY_MAX_TOKENS);

        $this->billing->record($conversation->user, $conversation, $conversation->scope, $response->usage->total());

        $conversation->forceFill([
            'summary' => mb_substr(mb_trim($response->content ?? ''), 0, 8000),
            'summarized_until_message_id' => $last->id,
        ])->save();

        return true;
    }

    /**
     * The messages after the summary, oldest first.
     *
     * @return Collection<int, AiMessage>
     */
    public function history(AiConversation $conversation): Collection
    {
        $query = $conversation->messages()->orderBy('id');
        if ($conversation->summarized_until_message_id !== null) {
            $query->where('id', '>', $conversation->summarized_until_message_id);
        }

        return $query->get()->values();
    }

    /**
     * OpenAI chat messages for the history. A tool call without a result (a crashed run) gets
     * a placeholder result, or providers reject the whole request.
     *
     * @param  Collection<int, AiMessage>  $history
     * @return list<array<string, mixed>>
     */
    public function format(Collection $history): array
    {
        $answered = $history->where('role', AiMessage::ROLE_TOOL)->pluck('tool_call_id')->filter()->flip();
        $messages = [];

        foreach ($history as $message) {
            if ($message->role === AiMessage::ROLE_TOOL) {
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) $message->tool_call_id,
                    'content' => self::truncate($message->content ?? '', self::TOOL_RESULT_BYTES),
                ];

                continue;
            }

            if ($message->role === AiMessage::ROLE_USER) {
                $messages[] = ['role' => 'user', 'content' => $message->content ?? ''];

                continue;
            }

            $calls = $message->tool_calls ?? [];
            $entry = ['role' => 'assistant', 'content' => $message->content ?? ''];
            if ($calls !== []) {
                $entry['tool_calls'] = $calls;
            }

            $messages[] = $entry;

            foreach ($calls as $call) {
                if (! $answered->has($call['id'])) {
                    $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => 'This tool call did not run.'];
                }
            }
        }

        return $messages;
    }

    private function estimate(AiMessage $message): int
    {
        $content = $message->content ?? '';
        if ($message->role === AiMessage::ROLE_TOOL) {
            $content = self::truncate($content, self::TOOL_RESULT_BYTES);
        }

        return ChatClient::estimateTokens($content.json_encode($message->tool_calls ?? [])) + 4;
    }

    private function transcriptLine(AiMessage $message): string
    {
        $content = mb_substr($message->content ?? '', 0, self::SUMMARY_MESSAGE_CHARS);

        return match ($message->role) {
            AiMessage::ROLE_USER => 'User: '.$content,
            AiMessage::ROLE_TOOL => 'Tool result: '.$content,
            default => 'Assistant: '.$content.(($message->tool_calls ?? []) === [] ? '' : ' [called: '.implode(', ', array_map(fn (array $call): string => $call['function']['name'], $message->tool_calls ?? [])).']'),
        };
    }

    private function providerOf(AiConversation $conversation): AiProvider
    {
        return AssistantProviders::resolve($conversation);
    }
}
