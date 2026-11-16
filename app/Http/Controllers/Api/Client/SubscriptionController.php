<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Exceptions\Billing\SubscriptionRenewalException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Http\Requests\Api\Client\Subscriptions\KeepTrialRequest;
use Pterodactyl\Http\Requests\Api\Client\Subscriptions\UpdateSubscriptionRequest;
use Pterodactyl\Http\Resources\Api\ApiResourceCollection;
use Pterodactyl\Http\Resources\Api\Client\ServerSubscriptionResource;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\SubscriptionService;
use Pterodactyl\Services\Billing\TrialService;
use Pterodactyl\Services\Query\ServerQuery;
use Pterodactyl\Services\Sleep\ServerSleep;
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
            'is_trial' => false,
            'trial' => null,
            'price_tokens' => 500,
            'period_days' => 30,
            'addons' => [['key' => 'backup', 'units' => 1]],
            'auto_renew' => true,
            'next_renewal_at' => '2026-12-15T12:00:00+00:00',
            'suspended_at' => null,
            'delete_at' => null,
            'can_renew_now' => true,
            'sleep' => [
                'allowed' => true,
                'supported' => true,
                'enabled' => true,
                'sleeping' => false,
                'sleep_started_at' => null,
                'idle_minutes' => 15,
                'discount_percent' => 50,
                'slept_seconds_this_period' => 432000,
                'saved_this_period' => 41,
                'last_saved_tokens' => 120,
            ],
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
            ->with(['server.egg', 'plan'])
            ->where('user_id', $request->user()->id)
            ->where('status', '!=', ServerSubscription::STATUS_CANCELLED)
            ->orderBy('id')
            ->get();

        return ServerSubscriptionResource::collection($subscriptions);
    }

    /**
     * Turn automatic renewal or auto-sleep on or off.
     */
    #[Endpoint('Update server subscription', 'Turns automatic renewal or auto-sleep on or off (send either or both). With automatic renewal off, the server is suspended when its period ends. With auto-sleep on, the server is stopped gracefully once nobody has played for the idle minutes of the plan, and the time it sleeps is discounted at the renewal; it needs a plan that allows it and a supported game (Minecraft Java); otherwise the answer is 422.')]
    #[BodyParam('auto_renew', 'boolean', 'Renew automatically from the token balance. Required without auto_sleep.', required: false, example: true)]
    #[BodyParam('auto_sleep', 'boolean', 'Let the server sleep while nobody plays. Required without auto_renew.', required: false, example: true)]
    #[ScribeResponse(self::SUBSCRIPTION_EXAMPLE, description: 'Subscription updated.')]
    public function update(UpdateSubscriptionRequest $request, ServerSleep $sleep, Server $server): ServerSubscriptionResource
    {
        $subscription = $this->subscriptionOf($request->user(), $server);

        if ($request->has('auto_sleep')) {
            $enable = $request->boolean('auto_sleep');
            if ($enable && ! $sleep->allowed($subscription)) {
                throw ValidationException::withMessages(['auto_sleep' => "This server's plan does not include auto-sleep."]);
            }

            if ($enable && ! ServerQuery::supports($server)) {
                throw ValidationException::withMessages(['auto_sleep' => 'Auto-sleep is not supported for this game yet.']);
            }

            $subscription->forceFill(['auto_sleep' => $enable, 'idle_checks' => 0])->save();

            Activity::event('server:subscription.auto-sleep')
                ->subject($server)
                ->property('auto_sleep', $enable ? 'on' : 'off')
                ->log();
        }

        if ($request->has('auto_renew')) {
            $subscription->forceFill(['auto_renew' => $request->boolean('auto_renew')])->save();

            Activity::event('server:subscription.auto-renew')
                ->subject($server)
                ->property('auto_renew', $subscription->auto_renew ? 'on' : 'off')
                ->log();
        }

        return ServerSubscriptionResource::make($subscription->fresh(['server.egg', 'plan']) ?? $subscription);
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
        throw_if($subscription->is_trial, SubscriptionRenewalException::class, 'This is a free trial: pick a billing period with "Keep this server" to keep it.');
        throw_unless($subscription->canRenewNow(), SubscriptionRenewalException::class, 'This server is already paid for the next period.');

        $subscriptions->renewNow($subscription);

        Activity::event('server:subscription.renew')
            ->subject($server)
            ->property('price_tokens', $subscription->price_tokens)
            ->log();

        return ServerSubscriptionResource::make($subscription->fresh(['server.egg', 'plan']) ?? $subscription);
    }

    /**
     * Keep a free trial server.
     */
    #[Endpoint('Keep trial server', 'Keeps a free trial server by paying its first billing period from the token balance (the prices are in the trial options of the subscription). The subscription becomes a normal one in place: the same server, renewing every period from now. A trial that already ended is unsuspended. A repeated request never charges twice.')]
    #[BodyParam('period_id', 'integer', 'The billing period to pay for.', required: true, example: 1)]
    #[BodyParam('expected_total', 'integer', 'The price the player confirmed; a different current price is refused.', required: false, example: 500)]
    #[ScribeResponse(self::SUBSCRIPTION_EXAMPLE, description: 'The server is kept and paid for one period.')]
    #[ScribeResponse(['errors' => [['code' => 'SubscriptionRenewalException', 'status' => '400', 'detail' => 'You need 500 tokens to keep this server. Top up your wallet and try again.']]], status: 400, description: 'The balance is too low, the period is not offered, the price changed or the server is not a trial.')]
    public function keep(KeepTrialRequest $request, TrialService $trials, SubscriptionService $subscriptions, Server $server): ServerSubscriptionResource
    {
        $subscription = $this->subscriptionOf($request->user(), $server);

        $trials->convert(
            $subscription,
            $request->integer('period_id'),
            $request->filled('expected_total') ? $request->integer('expected_total') : null,
            $subscriptions,
        );

        Activity::event('server:subscription.keep')
            ->subject($server)
            ->property('price_tokens', $subscription->price_tokens)
            ->property('period_days', $subscription->period_days)
            ->log();

        return ServerSubscriptionResource::make($subscription->fresh(['server.egg', 'plan']) ?? $subscription);
    }

    /**
     * Only the user who pays for a server sees or changes its billing.
     */
    private function subscriptionOf(User $user, Server $server): ServerSubscription
    {
        $subscription = ServerSubscription::query()
            ->with(['server.egg', 'plan'])
            ->where('server_id', $server->id)
            ->where('user_id', $user->id)
            ->where('status', '!=', ServerSubscription::STATUS_CANCELLED)
            ->first();

        return $subscription ?? throw new NotFoundHttpException(trans('exceptions.api.resource_not_found'));
    }
}
