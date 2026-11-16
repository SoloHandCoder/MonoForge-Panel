<?php

declare(strict_types=1);

namespace Pterodactyl\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Pterodactyl\Events\Server\Deleting;
use Pterodactyl\Extensions\Illuminate\Events\Contracts\SubscribesToEvents;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Services\Billing\Revenue\SubscriptionEndings;

/**
 * Counts the churn of a shop server deleted by anyone (its owner, an administrator or billing):
 * its subscription row is deleted with it. Runs inside the deletion's transaction.
 */
class SubscriptionEndingListener implements SubscribesToEvents
{
    public function deleting(Deleting $event): void
    {
        $subscription = ServerSubscription::query()->where('server_id', $event->server->id)->first();
        if ($subscription instanceof ServerSubscription) {
            SubscriptionEndings::serverDeleted($subscription);
        }
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Deleting::class, $this->deleting(...));
    }
}
