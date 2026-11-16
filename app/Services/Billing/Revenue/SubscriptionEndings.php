<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing\Revenue;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Billing\ServerSubscription;

/**
 * Records a subscription ending (churn) for the revenue dashboard. The subscription row goes
 * with its server, so the ending is written when it happens, inside the caller's transaction:
 * a rolled-back cancel or delete leaves no ending behind.
 */
final class SubscriptionEndings
{
    public const string TABLE = 'server_subscription_endings';

    public const string REASON_CANCELLED = 'cancelled';

    public const string REASON_UNPAID = 'unpaid';

    public const string REASON_DELETED = 'deleted';

    /** @var list<string> */
    public const array REASONS = [self::REASON_CANCELLED, self::REASON_UNPAID, self::REASON_DELETED];

    public static function record(ServerSubscription $subscription, string $reason): void
    {
        // Unique per subscription and reason: a retried delete never counts twice.
        DB::table(self::TABLE)->insertOrIgnore([
            'server_subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'plan_id' => $subscription->plan_id,
            'reason' => $reason,
            'price_tokens' => $subscription->price_tokens,
            'period_days' => $subscription->period_days,
            'created_at' => now(),
        ]);
    }

    /**
     * The server of the subscription is being deleted. A cancelled subscription was counted when
     * it was cancelled; a suspended one is deleted for not being paid.
     */
    public static function serverDeleted(ServerSubscription $subscription): void
    {
        if ($subscription->status === ServerSubscription::STATUS_CANCELLED) {
            return;
        }

        self::record($subscription, $subscription->isSuspended() ? self::REASON_UNPAID : self::REASON_DELETED);
    }
}
