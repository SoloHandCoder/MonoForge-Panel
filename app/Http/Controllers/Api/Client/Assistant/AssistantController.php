<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client\Assistant;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\GetAssistantStatusRequest;
use Pterodactyl\Models\Assistant\AiUserSetting;
use Pterodactyl\Services\Assistant\AssistantBilling;
use Pterodactyl\Services\Assistant\AssistantSettings;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Server Assistant', 'Chat with the AI assistant about a server. It can only do what your own permissions allow, and asks before sensitive actions.')]
class AssistantController extends ClientApiController
{
    public const array EXAMPLE = [
        'enabled' => true,
        'server_enabled' => true,
        'crash_doctor_enabled' => true,
        'free_limit' => 50000,
        'free_used' => 41000,
        'ratio' => 1000,
        'balance' => 250,
        'warning' => 'near_limit',
        'paused' => false,
    ];

    #[Endpoint('Get assistant status', 'Returns whether the AI assistant is turned on (and the AI crash doctor, crash_doctor_enabled, which also needs the server assistant), the free AI tokens of this month (free_limit, free_used), how many AI tokens one panel token buys (ratio), the token balance, and a warning: null, near_limit, free_exhausted (now paid from the balance) or no_balance (the assistant cannot be used until the wallet is topped up), and whether you paused the assistant (paused: no turn starts or continues until you resume it).')]
    #[ScribeResponse(self::EXAMPLE, description: 'Assistant status returned.')]
    public function __invoke(GetAssistantStatusRequest $request, AssistantBilling $billing): JsonResponse
    {
        return new JsonResponse([
            'enabled' => AssistantSettings::enabled(),
            'server_enabled' => AssistantSettings::serverEnabled(),
            'crash_doctor_enabled' => AssistantSettings::crashDoctorAvailable(),
            ...$billing->status($request->user()),
            'paused' => AiUserSetting::paused($request->user()),
        ]);
    }
}
