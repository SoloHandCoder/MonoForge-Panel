<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Servers;

use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Servers\ServerWriteRequest;
use Pterodactyl\Http\Requests\Api\Admin\Servers\Subscriptions\ExtendServerSubscriptionRequest;
use Pterodactyl\Http\Requests\Api\Admin\Servers\Subscriptions\GetServerSubscriptionRequest;
use Pterodactyl\Http\Resources\Api\Admin\ServerSubscriptionResource;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Billing\SubscriptionService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Servers', 'Create, update, retrieve, and operate servers.')]
class SubscriptionController extends AdminApiController
{
    public const array SUBSCRIPTION_EXAMPLE = [
        'object' => ServerSubscription::RESOURCE_NAME,
        'attributes' => [
            'id' => 1,
            'server_id' => 1,
            'user_id' => 1,
            'plan_id' => 1,
            'period_id' => 1,
            'server_identifier' => '1a7ce997',
            'server_name' => 'Survival',
            'status' => 'active',
            'price_tokens' => 500,
            'period_days' => 30,
            'addons' => [['key' => 'backup', 'units' => 1]],
            'auto_renew' => true,
            'next_renewal_at' => '2026-12-15T12:00:00+00:00',
            'suspended_at' => null,
            'delete_at' => null,
            'can_renew_now' => true,
            'created_at' => '2026-11-15T12:00:00+00:00',
            'updated_at' => '2026-11-15T12:00:00+00:00',
        ],
    ];

    /**
     * Get server subscription.
     */
    #[Endpoint('Get server subscription', 'Returns the shop billing of a server, or 404 when the server was not bought in the shop.')]
    #[ScribeResponse(self::SUBSCRIPTION_EXAMPLE, description: 'Subscription returned.')]
    #[ScribeResponse(['errors' => [['code' => 'NotFoundHttpException', 'status' => '404', 'detail' => 'The requested resource could not be found on the server.']]], status: 404, description: 'The server has no subscription.')]
    public function show(GetServerSubscriptionRequest $request, Server $server): ServerSubscriptionResource
    {
        return ServerSubscriptionResource::make($this->subscriptionOf($server));
    }

    /**
     * Extend server subscription.
     */
    #[Endpoint('Extend server subscription', 'Moves the next renewal later by a number of free days. A suspended subscription becomes active from now and its server is unsuspended.')]
    #[BodyParam('days', 'integer', 'Free days to add, 1 to 365.', example: 7)]
    #[ScribeResponse(self::SUBSCRIPTION_EXAMPLE, description: 'Subscription extended.')]
    public function extend(ExtendServerSubscriptionRequest $request, SubscriptionService $subscriptions, Server $server): ServerSubscriptionResource
    {
        $subscription = $this->subscriptionOf($server);
        $days = $request->integer('days');
        $subscriptions->extend($subscription, $days);

        Activity::event('admin:server.subscription-extend')
            ->subject($server)
            ->property(['name' => $server->name, 'days' => $days])
            ->log();

        return ServerSubscriptionResource::make($this->subscriptionOf($server));
    }

    /**
     * Cancel server subscription.
     */
    #[Endpoint('Cancel server subscription', 'Stops billing the server: no more renewals, suspensions or deletion. A server that billing had suspended is unsuspended; the server itself stays.')]
    #[ScribeResponse(self::SUBSCRIPTION_EXAMPLE, description: 'Subscription cancelled.')]
    public function cancel(ServerWriteRequest $request, SubscriptionService $subscriptions, Server $server): ServerSubscriptionResource
    {
        $subscription = $this->subscriptionOf($server);
        $subscriptions->cancel($subscription);

        Activity::event('admin:server.subscription-cancel')
            ->subject($server)
            ->property('name', $server->name)
            ->log();

        return ServerSubscriptionResource::make($this->subscriptionOf($server));
    }

    private function subscriptionOf(Server $server): ServerSubscription
    {
        $subscription = ServerSubscription::query()->with('server')->where('server_id', $server->id)->first();

        return $subscription ?? throw new NotFoundHttpException(trans('exceptions.api.resource_not_found'));
    }
}
