<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Client;

use Illuminate\Http\Request;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Services\Billing\TrialService;
use Pterodactyl\Services\Query\ServerQuery;
use Pterodactyl\Services\Sleep\ServerSleep;

/**
 * A shop server's billing as its owner sees it: what is charged, when, and the countdown to the
 * deletion while it is suspended, and pay-as-you-play (auto-sleep) when the plan allows it.
 *
 * @extends ClientResource<ServerSubscription>
 */
class ServerSubscriptionResource extends ClientResource
{
    public function resourceName(): string
    {
        return ServerSubscription::RESOURCE_NAME;
    }

    public function toArray(Request $request): array
    {
        $subscription = $this->resource;
        $sleep = resolve(ServerSleep::class);
        $bill = $sleep->bill($subscription);

        return [
            'server_identifier' => $subscription->server->uuidShort,
            'server_name' => $subscription->server->name,
            'status' => $subscription->status,
            'is_trial' => $subscription->is_trial,
            // A free trial: when it ends (or ended) and what keeping the server costs per period.
            'trial' => $subscription->is_trial ? [
                'ends_at' => $subscription->isTrial() ? $subscription->next_renewal_at->toAtomString() : $subscription->suspended_at?->toAtomString(),
                'options' => resolve(TrialService::class)->options($subscription),
            ] : null,
            'price_tokens' => $subscription->price_tokens,
            'period_days' => $subscription->period_days,
            'addons' => $subscription->addons ?? [],
            'auto_renew' => $subscription->auto_renew,
            'next_renewal_at' => $subscription->next_renewal_at->toAtomString(),
            'suspended_at' => $subscription->isSuspended() ? $subscription->suspended_at?->toAtomString() : null,
            'delete_at' => $subscription->delete_at?->toAtomString(),
            'can_renew_now' => $subscription->canRenewNow(),
            'sleep' => [
                'allowed' => $sleep->allowed($subscription),
                'supported' => ServerQuery::supports($subscription->server),
                'enabled' => $subscription->auto_sleep,
                'sleeping' => $subscription->isSleeping(),
                'sleep_started_at' => $subscription->isSleeping() ? $subscription->sleep_started_at?->toAtomString() : null,
                'idle_minutes' => $subscription->plan?->sleepIdleMinutes() ?? 0,
                // The plan's terms for sleep from now on; sleep already recorded keeps its own percent.
                'discount_percent' => $subscription->plan?->sleepDiscountPercent() ?? 0,
                'slept_seconds_this_period' => $bill->sleptSeconds,
                'saved_this_period' => $bill->saved,
                'last_saved_tokens' => $subscription->last_sleep_saved_tokens,
            ],
        ];
    }
}
