<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Exceptions\Assistant\AssistantUnavailableException;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiUsage;
use Pterodactyl\Models\Billing\TokenTransaction;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Services\Notices\NoticeService;

/**
 * Charges the server assistant's AI tokens: each user gets a free allowance per calendar
 * month, and what goes past it costs ceil(overflow / ratio) panel tokens, never more than
 * the user has. The admin assistant is recorded but never charged.
 *
 * Every recording runs under the user's row lock (the same lock TokenWallet takes), so two
 * turns at once can neither both use the last free tokens nor overdraw the wallet.
 */
class AssistantBilling
{
    public const string NOTICE_NEAR = 'ai.free_near';

    public const string NOTICE_EXHAUSTED = 'ai.free_exhausted';

    public const string WARNING_NEAR = 'near_limit';

    public const string WARNING_EXHAUSTED = 'free_exhausted';

    public const string WARNING_NO_BALANCE = 'no_balance';

    public function __construct(private readonly TokenWallet $wallet, private readonly NoticeService $notices) {}

    /** Free AI tokens the user has used this month. */
    public function freeUsed(User $user, ?string $period = null): int
    {
        $sum = AiUsage::query()
            ->where('user_id', $user->id)
            ->where('period', $period ?? AiUsage::currentPeriod())
            ->sum('free_tokens');

        return (int) $sum;
    }

    /** Panel tokens the assistant charged the user this month. */
    public function chargedThisMonth(User $user): int
    {
        $sum = AiUsage::query()
            ->where('user_id', $user->id)
            ->where('period', AiUsage::currentPeriod())
            ->sum('panel_tokens_charged');

        return (int) $sum;
    }

    public function freeRemaining(User $user): int
    {
        return max(0, AssistantSettings::freeTokensMonthly() - $this->freeUsed($user));
    }

    /**
     * What the usage meter shows.
     *
     * @return array{free_limit: int, free_used: int, ratio: int, balance: int, warning: string|null}
     */
    public function status(User $user): array
    {
        $limit = AssistantSettings::freeTokensMonthly();
        $used = min($limit, $this->freeUsed($user));
        $balance = $this->wallet->balance($user);

        return [
            'free_limit' => $limit,
            'free_used' => $used,
            'ratio' => AssistantSettings::ratio(),
            'balance' => $balance,
            'warning' => $this->warning($limit, $used, $balance),
        ];
    }

    /**
     * Refuses a model call when the user has neither free AI tokens nor panel tokens left.
     * The admin assistant is never refused.
     *
     * @throws AssistantUnavailableException
     */
    public function assertCanSpend(User $user, string $scope): void
    {
        if ($scope === AiConversation::SCOPE_ADMIN) {
            return;
        }

        if ($this->freeRemaining($user) > 0 || $this->wallet->balance($user) > 0) {
            return;
        }

        throw AssistantUnavailableException::noBalance();
    }

    /**
     * Records the AI tokens of one model call: free allowance first, then panel tokens for
     * the overflow, at most the user's balance.
     */
    public function record(User $user, ?AiConversation $conversation, string $scope, int $aiTokens): AiUsage
    {
        $aiTokens = max(0, $aiTokens);
        $period = AiUsage::currentPeriod();
        $limit = AssistantSettings::freeTokensMonthly();
        $ratio = AssistantSettings::ratio();

        $before = 0;
        $usage = DB::transaction(function () use ($user, $conversation, $scope, $aiTokens, $period, $limit, $ratio, &$before): AiUsage {
            // The wallet's lock: serialises this user's recordings and wallet changes.
            User::query()->whereKey($user->id)->lockForUpdate()->value('id');

            $before = $this->freeUsed($user, $period);
            $free = 0;
            $charged = 0;

            if ($scope !== AiConversation::SCOPE_ADMIN) {
                $free = min($aiTokens, max(0, $limit - $before));
                $overflow = $aiTokens - $free;

                if ($overflow > 0) {
                    $charged = min((int) ceil($overflow / $ratio), $this->wallet->balance($user));
                }
            }

            $usage = AiUsage::query()->create([
                'user_id' => $user->id,
                'conversation_id' => $conversation?->id,
                'scope' => $scope,
                'period' => $period,
                'ai_tokens' => $aiTokens,
                'free_tokens' => $free,
                'panel_tokens_charged' => $charged,
            ]);

            if ($charged > 0) {
                $this->wallet->debit($user, $charged, TokenTransaction::TYPE_AI_USAGE, 'AI assistant usage', AiUsage::RESOURCE_NAME, (string) $usage->id, [
                    'ai_tokens' => $aiTokens - $free,
                    'ratio' => $ratio,
                    'conversation' => $conversation?->uuid,
                ]);
            }

            return $usage;
        });

        if ($scope !== AiConversation::SCOPE_ADMIN && $usage->free_tokens > 0) {
            $this->notifyThresholds($user, $before, $before + $usage->free_tokens, $limit);
        }

        return $usage;
    }

    private function warning(int $limit, int $used, int $balance): ?string
    {
        if ($used >= $limit) {
            return $balance > 0 ? self::WARNING_EXHAUSTED : self::WARNING_NO_BALANCE;
        }

        return $used * 100 >= $limit * AssistantSettings::warnPercent() ? self::WARNING_NEAR : null;
    }

    /** One notice per kind and month, when this recording crossed the threshold. */
    private function notifyThresholds(User $user, int $before, int $after, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }

        $warnAt = (int) ceil($limit * AssistantSettings::warnPercent() / 100);

        if ($after >= $limit && $before < $limit) {
            $this->notifyOnce($user, self::NOTICE_EXHAUSTED, 'warning', 'Free AI tokens used up', sprintf(
                'You have used all %s free AI tokens for this month. The assistant now costs 1 panel token per %s AI tokens.',
                number_format($limit),
                number_format(AssistantSettings::ratio()),
            ));

            return;
        }

        if ($after >= $warnAt && $before < $warnAt) {
            $this->notifyOnce($user, self::NOTICE_NEAR, 'info', 'Free AI tokens running low', sprintf(
                'You have used %d%% of your %s free AI tokens for this month.',
                (int) floor($after * 100 / $limit),
                number_format($limit),
            ));
        }
    }

    private function notifyOnce(User $user, string $kind, string $level, string $title, string $body): void
    {
        $sent = UserNotice::query()
            ->where('user_id', $user->id)
            ->where('kind', $kind)
            ->where('created_at', '>=', CarbonImmutable::now()->startOfMonth())
            ->exists();

        if (! $sent) {
            $this->notices->notify($user, $level, $title, $body, $kind, 'Open wallet', '/account/wallet');
        }
    }
}
