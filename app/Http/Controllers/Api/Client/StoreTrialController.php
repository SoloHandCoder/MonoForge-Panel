<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Requests\Api\Client\Store\StartTrialRequest;
use Pterodactyl\Http\Resources\Api\Client\StoreTrialResource;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Services\Billing\StorePurchaseService;
use Pterodactyl\Services\Billing\TrialSettings;
use Pterodactyl\Services\Legal\LegalSettings;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Store', 'Browse the server store and buy servers with tokens.')]
class StoreTrialController extends ClientApiController
{
    public const array TRIAL_EXAMPLE = [
        'object' => StoreTrialResource::RESOURCE_NAME,
        'attributes' => [
            'server' => [
                'identifier' => '1a7ce997',
                'uuid' => '1a7ce997-259b-452e-8b4e-cecc464142ca',
                'name' => 'Survival',
            ],
            'hours' => 48,
            'ends_at' => '2026-11-18T12:00:00+00:00',
            'replayed' => false,
            'address' => 'example.joinmc.link',
        ],
    ];

    public function __construct(private readonly StorePurchaseService $purchases) {}

    /**
     * Start a free trial server.
     */
    #[Endpoint('Start free trial', "Creates the player's one free trial server on a trialable plan, which starts installing; nothing is charged. When the trial ends the server is suspended and deleted after the shop's grace period unless the player keeps it (Keep trial server). Refused with 403 for an account that already had a trial or bought a server, a linked Discord or Google account or a network (/24 or /48) that already started one in the last 30 days, a missing linked account when the shop requires one, or when every trial slot is taken; the capacity limits apply like a purchase. Repeating a request with the same idempotency key returns the same server. Browser sessions only.")]
    #[BodyParam('g-recaptcha-response', 'string', 'The captcha token (Turnstile action trial), required when the panel uses a captcha.', required: false, example: 'XXXX.DUMMY.TOKEN')]
    #[ScribeResponse(self::TRIAL_EXAMPLE, status: 201, description: 'Trial server created and installing.')]
    #[ScribeResponse(self::TRIAL_EXAMPLE, status: 200, description: 'The idempotency key already started this trial.')]
    #[ScribeResponse(['errors' => [['code' => 'StorePurchaseException', 'status' => '403', 'detail' => 'You have already used your free trial.']]], status: 403, description: 'The player cannot start a free trial.')]
    #[ScribeResponse(status: 409, description: 'The store is at its capacity limits, busy, or the user has to accept the updated Terms of Service first.')]
    #[ResponseField('attributes.ends_at', 'string', 'When the trial ends (ISO 8601).', nullable: true)]
    #[ResponseField('attributes.address', 'string', 'The playit.gg address of the server once its tunnel is ready; null until then.', nullable: true)]
    public function store(StartTrialRequest $request): JsonResponse
    {
        LegalSettings::ensureAccepted($request->user());

        $result = $this->purchases->startTrial($request->user(), $request->order(), $request->ip());
        $subscription = ServerSubscription::query()->where('server_id', $result->server->id)->first();

        if (! $result->replayed) {
            Activity::event('user:store.trial')
                ->subject($result->server)
                ->property('name', $result->server->name)
                ->property('hours', TrialSettings::hours())
                ->log();
        }

        return StoreTrialResource::make([
            'server' => $result->server,
            'hours' => TrialSettings::hours(),
            'ends_at' => $subscription?->next_renewal_at->toAtomString(),
            'replayed' => $result->replayed,
            'address' => $result->address,
        ])->respond($result->replayed ? Response::HTTP_OK : Response::HTTP_CREATED);
    }
}
