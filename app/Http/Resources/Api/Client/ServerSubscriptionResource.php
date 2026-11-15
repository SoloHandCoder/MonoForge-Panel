<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Client;

use Illuminate\Http\Request;
use Pterodactyl\Models\Billing\ServerSubscription;

/**
 * A shop server's billing as its owner sees it: what is charged, when, and the countdown to the
 * deletion while it is suspended.
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

        return [
            'server_identifier' => $subscription->server->uuidShort,
            'server_name' => $subscription->server->name,
            'status' => $subscription->status,
            'price_tokens' => $subscription->price_tokens,
            'period_days' => $subscription->period_days,
            'addons' => $subscription->addons ?? [],
            'auto_renew' => $subscription->auto_renew,
            'next_renewal_at' => $subscription->next_renewal_at->toAtomString(),
            'suspended_at' => $subscription->isSuspended() ? $subscription->suspended_at?->toAtomString() : null,
            'delete_at' => $subscription->delete_at?->toAtomString(),
            'can_renew_now' => $subscription->canRenewNow(),
        ];
    }
}
