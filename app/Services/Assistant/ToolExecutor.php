<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant;

use Carbon\CarbonImmutable;
use Pterodactyl\Contracts\Assistant\AlwaysAsks;
use Pterodactyl\Contracts\Assistant\AssistantTool;
use Pterodactyl\Contracts\Assistant\PreparesToolCall;
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

    public const string EXPIRED = 'Error: nobody allowed this action in time, so it did not run. Ask again if it is still needed.';

    public const string STOPPED = 'Error: the user stopped the assistant, so this action did not run.';

    /** At most this many tool calls of one model answer run; the rest fail. */
    public const int MAX_CALLS_PER_STEP = 8;

    public const string TOO_MANY_CALLS = 'Error: too many tool calls in one step. Call at most 8 tools at a time.';

    public function __construct(private readonly ToolRegistry $registry) {}

    public static function context(AiConversation $conversation): ToolContext
    {
        return new ToolContext($conversation->user, $conversation->scope, $conversation->server, $conversation);
    }

    /**
     * Stores the model's tool calls for an assistant message, deciding for each whether it
     * needs the user's confirmation. The tool settles the arguments first (paths resolved and
     * checked), and those prepared arguments are what the label and details show, what is
     * stored, and what later runs. A call whose arguments cannot run fails right away, as do
     * the calls past the first MAX_CALLS_PER_STEP of one answer.
     *
     * @param  list<ChatToolCall>  $calls
     */
    public function record(AiConversation $conversation, AiMessage $message, array $calls): void
    {
        $context = self::context($conversation);

        foreach ($calls as $index => $call) {
            $tool = $index < self::MAX_CALLS_PER_STEP ? $this->registry->find($call->name, $context) : null;
            $arguments = $call->arguments ?? [];
            $needsConfirmation = false;
            $alwaysAsk = false;
            $label = $call->name;
            $details = null;
            $error = null;

            if ($index >= self::MAX_CALLS_PER_STEP) {
                $error = self::TOO_MANY_CALLS;
            } elseif ($call->arguments === null) {
                $error = 'Error: the arguments were not valid JSON. Call the tool again with a JSON object.';
            } elseif ($tool instanceof AssistantTool) {
                try {
                    $arguments = $tool instanceof PreparesToolCall ? $tool->prepareArguments($arguments, $context) : $arguments;
                } catch (ToolException $exception) {
                    $error = 'Error: '.$exception->getMessage();
                } catch (Throwable $exception) {
                    report($exception);
                    $error = 'Error: the tool could not read these arguments.';
                }

                if ($error === null) {
                    [$needsConfirmation, $label, $details, $alwaysAsk] = $this->describe($tool, $arguments, $context);
                }
            }

            $row = AiToolCall::query()->create([
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'call_id' => $call->id,
                'name' => mb_substr($call->name, 0, 191),
                'label' => $label,
                'arguments' => $arguments,
                'details' => $details,
                'needs_confirmation' => $needsConfirmation,
                'always_ask' => $needsConfirmation && $alwaysAsk,
                'status' => AiToolCall::STATUS_PENDING,
            ]);

            if ($error !== null) {
                $this->finish($row, AiToolCall::STATUS_FAILED, $error);
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
            $this->finish($call, AiToolCall::STATUS_DONE, $result->content, $result->display, $result->private);
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

    /**
     * Allows a call that waits for confirmation without a click, because the conversation is in
     * "Allow everything" or the user chose "Allow until my next message" ($reason). A call that
     * always asks is never allowed this way. False if the call was not waiting.
     */
    public function autoApprove(AiToolCall $call, User $user, string $reason): bool
    {
        $now = CarbonImmutable::now();
        $changed = AiToolCall::query()
            ->whereKey($call->id)
            ->where('status', AiToolCall::STATUS_PENDING)
            ->where('needs_confirmation', true)
            ->where('always_ask', false)
            ->update([
                'status' => AiToolCall::STATUS_APPROVED,
                'decided_by' => $user->id,
                'decided_at' => $now,
                'auto_approved' => $reason,
                'updated_at' => $now,
            ]);

        $call->refresh();

        return $changed > 0;
    }

    /**
     * Ends a call that has not started (waiting for a decision, or allowed and not yet run)
     * because the user stopped the turn; the model is told it did not run. False if the call
     * had started or ended already.
     */
    public function cancel(AiToolCall $call): bool
    {
        $changed = AiToolCall::query()
            ->whereKey($call->id)
            ->whereIn('status', [AiToolCall::STATUS_PENDING, AiToolCall::STATUS_APPROVED])
            ->update(['status' => AiToolCall::STATUS_FAILED, 'updated_at' => CarbonImmutable::now()]);

        if ($changed === 0) {
            return false;
        }

        $this->finish($call, AiToolCall::STATUS_FAILED, self::STOPPED);

        return true;
    }

    /**
     * Ends a call that waited too long for a decision; the model is told it did not run.
     * False if the call was not waiting.
     */
    public function expire(AiToolCall $call): bool
    {
        $changed = AiToolCall::query()
            ->whereKey($call->id)
            ->where('status', AiToolCall::STATUS_PENDING)
            ->where('needs_confirmation', true)
            ->update(['status' => AiToolCall::STATUS_FAILED, 'updated_at' => CarbonImmutable::now()]);

        if ($changed === 0) {
            return false;
        }

        $this->finish($call, AiToolCall::STATUS_FAILED, self::EXPIRED);

        return true;
    }

    /**
     * Whether the prepared call needs a click, its label, its full details and whether it always
     * asks, even in "Allow everything".
     *
     * @param  array<string, mixed>  $arguments
     * @return array{bool, string, list<array{label: string, value: string}>|null, bool}
     */
    private function describe(AssistantTool $tool, array $arguments, ToolContext $context): array
    {
        try {
            $needsConfirmation = $tool->needsConfirmation($arguments, $context);
        } catch (Throwable $throwable) {
            // When in doubt, a human decides.
            report($throwable);
            $needsConfirmation = true;
        }

        try {
            $label = mb_trim($tool->describeCall($arguments));
            $label = $label === '' ? $tool->name() : (mb_strlen($label) > 191 ? mb_substr($label, 0, 190).'…' : $label);
        } catch (Throwable) {
            $label = $tool->name();
        }

        try {
            $details = $tool instanceof PreparesToolCall ? $tool->callDetails($arguments, $context) : null;
        } catch (Throwable $throwable) {
            report($throwable);
            $details = null;
        }

        try {
            $alwaysAsk = $tool instanceof AlwaysAsks && $tool->alwaysAsks($arguments, $context);
        } catch (Throwable $throwable) {
            report($throwable);
            $alwaysAsk = true;
        }

        return [$needsConfirmation, $label, $details, $alwaysAsk];
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
     * Stores the result. The model's copy goes into the history; a private result (a secret
     * the user allowed to read) is kept encrypted, for the user's card only.
     *
     * @param  array<string, mixed>  $display
     */
    private function finish(AiToolCall $call, string $status, string $result, array $display = [], ?string $private = null): void
    {
        $stored = ConversationContext::truncate($result, AiToolCall::MAX_RESULT_BYTES);

        $call->forceFill([
            'status' => $status,
            'result' => $stored,
            'private_result' => $private === null ? null : ConversationContext::truncate($private, AiToolCall::MAX_RESULT_BYTES),
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
