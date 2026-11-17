<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant;

use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiToolCall;

/**
 * Allows tool calls that wait for confirmation without a click, decided on the server when the
 * loop reaches the call (never on what the browser showed):
 *
 *  - "Allow until my next message": the user chose it on a card of this turn, and has not
 *    sent a new message since (the allowance is tied to their newest message's id), or
 *  - "Allow everything": the conversation is in that mode and the panel allows the mode.
 *
 * A call that always asks (AlwaysAsks: many files, world folders, tokens, suspensions,
 * announcements) still waits for a click. The call then runs like any allowed one: the tool is
 * looked up again for the user and checks their permissions itself, and every model call
 * still checks the user's balance. Each auto-allowed call is logged in the activity log.
 */
class AutoApproval
{
    /** How the activity log names the reasons. */
    public const array REASONS = [
        AiToolCall::AUTO_ALLOW_EVERYTHING => 'Allow everything',
        AiToolCall::AUTO_UNTIL_NEXT_MESSAGE => 'Allow until my next message',
    ];

    public function __construct(private readonly ToolExecutor $executor) {}

    /** Why the call may run without a click, or null when it has to wait for one. */
    public static function reason(AiConversation $conversation, AiToolCall $call): ?string
    {
        if ($call->status !== AiToolCall::STATUS_PENDING || ! $call->needs_confirmation || $call->always_ask) {
            return null;
        }

        if ($conversation->allowsUntilNextMessage()) {
            return AiToolCall::AUTO_UNTIL_NEXT_MESSAGE;
        }

        if ($conversation->approval_mode === AiConversation::APPROVAL_ALLOW_EVERYTHING && AssistantSettings::allowEverythingEnabled()) {
            return AiToolCall::AUTO_ALLOW_EVERYTHING;
        }

        return null;
    }

    /**
     * Allows the call when one of the rules holds. Returns whether it was allowed.
     */
    public function apply(AiConversation $conversation, AiToolCall $call): bool
    {
        $conversation->refresh();
        $reason = self::reason($conversation, $call);
        if ($reason === null || ! $this->executor->autoApprove($call, $conversation->user, $reason)) {
            return false;
        }

        $event = $conversation->scope === AiConversation::SCOPE_ADMIN ? 'admin:assistant.auto-approve' : 'server:assistant.auto-approve';
        $activity = Activity::event($event)->actor($conversation->user);
        if ($conversation->scope === AiConversation::SCOPE_SERVER && $conversation->server !== null) {
            $activity->subject($conversation->server);
        }

        $activity->property([
            'tool' => $call->name,
            'label' => ConversationPresenter::activityLabel($call),
            'reason' => self::REASONS[$reason],
        ])->log();

        return true;
    }
}
