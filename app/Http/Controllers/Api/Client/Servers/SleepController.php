<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\GetServerRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\WakeServerRequest;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Query\ServerQuery;
use Pterodactyl\Services\Sleep\ServerSleep;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Server Overview', 'Inspect and control an accessible server.')]
class SleepController extends ClientApiController
{
    public const string RESOURCE_NAME = 'server_sleep';

    private const array SLEEP_EXAMPLE = [
        'object' => self::RESOURCE_NAME,
        'attributes' => [
            'available' => true,
            'supported' => true,
            'enabled' => true,
            'sleeping' => true,
            'sleep_started_at' => '2026-11-20T03:15:00+00:00',
            'can_wake' => true,
        ],
    ];

    private const array NOT_SLEEPING_ERROR = [
        'errors' => [[
            'code' => 'ConflictHttpException',
            'status' => '409',
            'detail' => 'This server is not sleeping.',
        ]],
    ];

    private const array WAKE_FAILED_ERROR = [
        'errors' => [[
            'code' => 'ServiceUnavailableHttpException',
            'status' => '503',
            'detail' => 'The server could not be woken right now. Try again in a minute.',
        ]],
    ];

    public function __construct(private readonly ServerSleep $sleep) {}

    /**
     * Whether the server sleeps (pay-as-you-play).
     */
    #[Endpoint('Get server sleep state', 'Returns whether auto-sleep stopped the server (it sleeps until somebody starts it), whether its plan and game allow auto-sleep, and whether the viewer may wake it. Servers that were not bought in the shop never sleep.')]
    #[ScribeResponse(self::SLEEP_EXAMPLE, description: 'Sleep state returned.')]
    #[ResponseField('attributes.available', 'boolean', 'The plan allows auto-sleep and the game is supported.', example: true)]
    #[ResponseField('attributes.supported', 'boolean', 'The game tells who is online (Minecraft Java for now).', example: true)]
    #[ResponseField('attributes.enabled', 'boolean', 'The owner turned auto-sleep on.', example: true)]
    #[ResponseField('attributes.sleeping', 'boolean', 'Auto-sleep stopped the server and nobody has started it since.', example: true)]
    #[ResponseField('attributes.sleep_started_at', 'string', 'When it fell asleep (ISO 8601), or null.', required: false, example: '2026-11-20T03:15:00+00:00', nullable: true)]
    #[ResponseField('attributes.can_wake', 'boolean', 'The viewer may wake it (needs control.start).', example: true)]
    public function show(GetServerRequest $request, Server $server): JsonResponse
    {
        $subscription = ServerSubscription::query()->with(['server.egg', 'plan'])->where('server_id', $server->id)->first();
        $shop = $subscription instanceof ServerSubscription;
        $sleeping = $shop && $subscription->isSleeping();

        return new JsonResponse([
            'object' => self::RESOURCE_NAME,
            'attributes' => [
                'available' => $shop && $this->sleep->available($subscription),
                'supported' => ServerQuery::supports($server),
                'enabled' => $shop && $subscription->auto_sleep,
                'sleeping' => $sleeping,
                'sleep_started_at' => $sleeping ? $subscription->sleep_started_at?->toAtomString() : null,
                'can_wake' => $request->user()->can(Permissions::ControlStart->value, $server),
            ],
        ]);
    }

    /**
     * Wake a sleeping server.
     */
    #[Endpoint('Wake server', 'Starts a server that auto-sleep stopped. Needs the control.start permission.')]
    #[ScribeResponse(status: 204, description: 'The server is starting.')]
    #[ScribeResponse(self::NOT_SLEEPING_ERROR, status: 409, description: 'The server is not sleeping.')]
    #[ScribeResponse(self::WAKE_FAILED_ERROR, status: 503, description: 'Wings did not accept the start; try again later.')]
    public function wake(WakeServerRequest $request, Server $server): Response
    {
        throw_unless($this->sleep->isSleeping($server), ConflictHttpException::class, 'This server is not sleeping.');
        throw_unless($this->sleep->wake($server), ServiceUnavailableHttpException::class, null, 'The server could not be woken right now. Try again in a minute.');

        return $this->returnNoContent();
    }
}
