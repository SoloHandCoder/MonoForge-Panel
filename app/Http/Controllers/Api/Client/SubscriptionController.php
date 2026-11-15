<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Exceptions\Billing\SubscriptionRenewalException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Http\Requests\Api\Client\Subscriptions\UpdateSubscriptionRequest;
use Pterodactyl\Http\Resources\Api\ApiResourceCollection;
use Pterodactyl\Http\Resources\Api\Client\ServerSubscriptionResource;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\SubscriptionService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Server Subscriptions', "The billing of the authenticated user's shop servers.")]
class SubscriptionController extends ClientApiController
{
    public const array SUBSCRIPTION_EXAMPLE = [
        'object' => ServerSubscription::RESOURCE_NAME,
        'attributes' => [
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
        ],
    ];

    /**
     * List the user's server subscriptions.
     */
    #[Endpoint('List server subscriptions', 'Returns the billing of every shop server the authenticated user pays for, including the deletion countdown of suspended ones.')]
    #[ScribeResponse(['object' => 'list', 'data' => [self::SUBSCRIPTION_EXAMPLE]], description: 'Subscriptions returned.')]
    public function index(ClientApiRequest $request): ApiResourceCollection
    {
        $subscriptions = ServerSubscription::query()
            ->with('server')
            ->where('user_id', $request->user()->id)
            ->where('status', '!=', ServerSubscription::STATUS_CANCELLED)
            ->orderBy('id')
            ->get();

        return ServerSubscriptionResource::collection($subscriptions);
    }

    /**
     * Turn automatic renewal on or off.
     */
    #[Endpoint('Update server subscription', 'Turns automatic renewal on or off. With it off, the server is suspended when its period ends.')]
    #[BodyParam('auto_renew', 'boolean', 'Renew automatically from the token balance.', example: true)]
    #[ScribeResponse(self::SUBSCRIPTION_EXAMPLE, description: 'Subscription updated.')]
    public function update(UpdateSubscriptionRequest $request, Server $server): ServerSubscriptionResource
    {
        $subscription = $this->subscriptionOf($request->user(), $server);
        $subscription->forceFill(['auto_renew' => $request->boolean('auto_renew')])->save();

        Activity::event('server:subscription.auto-renew')
            ->subject($server)
            ->property('auto_renew', $subscription->auto_renew ? 'on' : 'off')
            ->log();

        return ServerSubscriptionResource::make($subscription->fresh(['server']) ?? $subscription);
    }

    /**
     * Renew now.
     */
    #[Endpoint('Renew server subscription', 'Pays a suspended server from the token balance, which unsuspends it, or pays the next period of an active one ahead of time (one period at most).')]
    #[ScribeResponse(self::SUBSCRIPTION_EXAMPLE, description: 'Subscription renewed.')]
    #[ScribeResponse(['errors' => [['code' => 'SubscriptionRenewalException', 'status' => '400', 'detail' => 'You need 500 tokens to renew this server. Top up your wallet and try again.']]], status: 400, description: 'The balance is too low, or the next period is already paid.')]
    public function renew(ClientApiRequest $request, SubscriptionService $subscriptions, Server $server): ServerSubscriptionResource
    {
        $subscription = $this->subscriptionOf($request->user(), $server);
        throw_unless($subscription->canRenewNow(), SubscriptionRenewalException::class, 'This server is already paid for the next period.');

        $subscriptions->renewNow($subscription);

        Activity::event('server:subscription.renew')
            ->subject($server)
            ->property('price_tokens', $subscription->price_tokens)
            ->log();

        return ServerSubscriptionResource::make($subscription->fresh(['server']) ?? $subscription);
    }

    /**
     * Only the user who pays for a server sees or changes its billing.
     */
    private function subscriptionOf(User $user, Server $server): ServerSubscription
    {
        $subscription = ServerSubscription::query()
            ->with('server')
            ->where('server_id', $server->id)
            ->where('user_id', $user->id)
            ->where('status', '!=', ServerSubscription::STATUS_CANCELLED)
            ->first();

        return $subscription ?? throw new NotFoundHttpException(trans('exceptions.api.resource_not_found'));
    }
}
