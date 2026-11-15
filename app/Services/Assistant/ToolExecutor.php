<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant;

use Carbon\CarbonImmutable;
use Pterodactyl\Contracts\Assistant\AssistantTool;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiMessage;
use Pterodactyl\Models\Assistant\AiToolCall;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Assistant\Chat\ChatToolCall;
use Throwable;

/**
 * Records the tool calls the model asks for and runs them. A call always runs for the
 * conversation's own user, with the tool looked up again in the registry right before it
 * runs, so a permission removed since the model was called is respected.
 */
class ToolExecutor
{
    public const string DENIED = 'The user denied this action.';

    public function __construct(private readonly ToolRegistry $registry) {}

    public static function context(AiConversation $conversation): ToolContext
    {
        return new ToolContext($conversation->user, $conversation->scope, $conversation->server);
    }

    /**
     * Stores the model's tool calls for an assistant message, deciding for each whether it
     * needs the user's confirmation. A call with unreadable arguments fails right away.
     *
     * @param  list<ChatToolCall>  $calls
     */
    public function record(AiConversation $conversation, AiMessage $message, array $calls): void
    {
        $context = self::context($conversation);

        foreach ($calls as $call) {
            $tool = $this->registry->find($call->name, $context);
            $arguments = $call->arguments ?? [];
            $needsConfirmation = false;
            $label = $call->name;

            if ($tool instanceof AssistantTool && $call->arguments !== null) {
                try {
                    $needsConfirmation = $tool->needsConfirmation($arguments, $context);
                } catch (Throwable $exception) {
                    // When in doubt, a human decides.
                    report($exception);
                    $needsConfirmation = true;
                }

                try {
                    $label = mb_substr(mb_trim($tool->describeCall($arguments)), 0, 191) ?: $call->name;
                } catch (Throwable) {
                    $label = $call->name;
                }
            }

            $row = AiToolCall::query()->create([
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'call_id' => $call->id,
                'name' => mb_substr($call->name, 0, 191),
                'label' => $label,
                'arguments' => $arguments,
                'needs_confirmation' => $needsConfirmation,
                'status' => AiToolCall::STATUS_PENDING,
            ]);

            if ($call->arguments === null) {
                $this->finish($row, AiToolCall::STATUS_FAILED, 'Error: the arguments were not valid JSON. Call the tool again with a JSON object.');
            }
        }
    }

    /**
     * Runs a pending (auto) or approved call and stores its result as a tool message.
     */
    public function run(AiConversation $conversation, AiToolCall $call): void
    {
        $claimed = AiToolCall::query()
            ->whereKey($call->id)
            ->whereIn('status', [AiToolCall::STATUS_PENDING, AiToolCall::STATUS_APPROVED])
            ->update(['status' => AiToolCall::STATUS_RUNNING, 'updated_at' => CarbonImmutable::now()]);

        if ($claimed === 0) {
            return;
        }

        $call->refresh();
        $context = self::context($conversation);
        $tool = $this->registry->find($call->name, $context);

        if (! $tool instanceof AssistantTool) {
            $message = $this->registry->has($conversation->scope, $call->name)
                ? 'Error: you do not have permission to use this tool.'
                : "Error: there is no tool named \"{$call->name}\".";
            $this->finish($call, AiToolCall::STATUS_FAILED, $message);

            return;
        }

        try {
            $result = $tool->execute($call->arguments, $context);
            $this->finish($call, AiToolCall::STATUS_DONE, $result->content, $result->display);
        } catch (ToolException $exception) {
            $this->finish($call, AiToolCall::STATUS_FAILED, 'Error: '.$exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $this->finish($call, AiToolCall::STATUS_FAILED, 'Error: the tool failed unexpectedly.');
        }
    }

    /** Records the user's click on Allow for a call waiting for it; false if it was not waiting. */
    public function approve(AiToolCall $call, User $user): bool
    {
        return $this->decide($call, $user, AiToolCall::STATUS_APPROVED);
    }

    /** Records the user's click on Deny and tells the model; false if the call was not waiting. */
    public function deny(AiToolCall $call, User $user): bool
    {
        if (! $this->decide($call, $user, AiToolCall::STATUS_DENIED)) {
            return false;
        }

        $this->finish($call, AiToolCall::STATUS_DENIED, self::DENIED);

        return true;
    }

    private function decide(AiToolCall $call, User $user, string $status): bool
    {
        $now = CarbonImmutable::now();
        $changed = AiToolCall::query()
            ->whereKey($call->id)
            ->where('status', AiToolCall::STATUS_PENDING)
            ->where('needs_confirmation', true)
            ->update(['status' => $status, 'decided_by' => $user->id, 'decided_at' => $now, 'updated_at' => $now]);

        $call->refresh();

        return $changed > 0;
    }

    /**
     * @param  array<string, mixed>  $display
     */
    private function finish(AiToolCall $call, string $status, string $result, array $display = []): void
    {
        $stored = ConversationContext::truncate($result, AiToolCall::MAX_RESULT_BYTES);

        $call->forceFill([
            'status' => $status,
            'result' => $stored,
            'display' => $display === [] ? null : $display,
        ])->save();

        AiMessage::query()->create([
            'conversation_id' => $call->conversation_id,
            'role' => AiMessage::ROLE_TOOL,
            'content' => $stored,
            'tool_call_id' => $call->call_id,
        ]);
    }
}
