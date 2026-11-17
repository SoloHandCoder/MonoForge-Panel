<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client\Assistant;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\UpdateAssistantPauseRequest;
use Pterodactyl\Models\Assistant\AiUserSetting;
use Pterodactyl\Services\Assistant\AssistantBilling;
use Pterodactyl\Services\Assistant\AssistantSettings;
use Pterodactyl\Services\Assistant\ConversationService;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Server Assistant', 'Chat with the AI assistant about a server. It can only do what your own permissions allow, and asks before sensitive actions.')]
class AssistantPauseController extends ClientApiController
{
    #[Endpoint('Pause or resume the assistant', 'Pauses the AI assistant for you (both the server and the admin assistant), or resumes it. While paused no turn starts or continues, so nothing is spent; pausing also stops every turn the assistant is working on or waiting with. Returns the assistant status.')]
    #[BodyParam('paused', 'boolean', 'true to pause, false to resume.', required: true, example: true)]
    #[ScribeResponse(AssistantController::EXAMPLE, description: 'Assistant status returned.')]
    public function __invoke(UpdateAssistantPauseRequest $request, ConversationService $conversations, AssistantBilling $billing): JsonResponse
    {
        $conversations->setPaused($request->user(), $request->paused());

        return new JsonResponse([
            'enabled' => AssistantSettings::enabled(),
            'server_enabled' => AssistantSettings::serverEnabled(),
            'crash_doctor_enabled' => AssistantSettings::crashDoctorAvailable(),
            ...$billing->status($request->user()),
            'paused' => AiUserSetting::paused($request->user()),
        ]);
    }
}
