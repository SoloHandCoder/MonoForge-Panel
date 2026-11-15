<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Admin;

use Illuminate\Http\Request;
use Pterodactyl\Models\Billing\ServerSubscription;

/**
 * A shop server's billing, with the catalog ids it was bought from.
 *
 * @extends AdminResource<ServerSubscription>
 */
class ServerSubscriptionResource extends AdminResource
{
    public function resourceName(): string
    {
        return ServerSubscription::RESOURCE_NAME;
    }

    public function toArray(Request $request): array
    {
        $subscription = $this->resource;

        return [
            'id' => $subscription->id,
            'server_id' => $subscription->server_id,
            'user_id' => $subscription->user_id,
            'plan_id' => $subscription->plan_id,
            'period_id' => $subscription->period_id,
            'server_identifier' => $subscription->server->uuidShort,
            'server_name' => $subscription->server->name,
            'status' => $subscription->status,
            'price_tokens' => $subscription->price_tokens,
            'period_days' => $subscription->period_days,
            'addons' => $subscription->addons ?? [],
            'auto_renew' => $subscription->auto_renew,
            'next_renewal_at' => $subscription->next_renewal_at->toAtomString(),
            'suspended_at' => $subscription->suspended_at?->toAtomString(),
            'delete_at' => $subscription->delete_at?->toAtomString(),
            'can_renew_now' => $subscription->canRenewNow(),
            'created_at' => $this->formatTimestamp($subscription->created_at),
            'updated_at' => $this->formatTimestamp($subscription->updated_at),
        ];
    }
}
