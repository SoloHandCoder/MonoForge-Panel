<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Assistant;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Assistant\AdminAssistantRequest;
use Pterodactyl\Http\Requests\Api\Admin\Assistant\UpdateAssistantSettingsRequest;
use Pterodactyl\Services\Assistant\AssistantSettings;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('AI Assistant', 'The admin AI assistant, its providers, settings and usage. Root administrators only, from a panel session.')]
class AssistantSettingsController extends AdminApiController
{
    private const array EXAMPLE = [
        'enabled' => true,
        'server_enabled' => true,
        'admin_enabled' => true,
        'crash_doctor_enabled' => true,
        'default_provider_id' => 1,
        'ratio' => 1000,
        'free_tokens_monthly' => 50000,
        'warn_percent' => 80,
        'max_tool_iterations' => 8,
        'context_tokens' => 24000,
        'max_output_tokens' => 2048,
    ];

    #[Endpoint('Get AI assistant settings', 'Returns whether the assistant is on (overall, on server pages, in the admin area), whether the AI crash doctor is offered when a server crashes (crash_doctor_enabled; it also needs the server assistant on), the default provider, the panel token price (ratio: AI tokens per panel token), the free AI tokens per user and month, when the free allowance warning shows (warn_percent), and the loop and context limits.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Settings returned.')]
    public function index(AdminAssistantRequest $request): JsonResponse
    {
        return new JsonResponse(AssistantSettings::all());
    }

    #[Endpoint('Update AI assistant settings', 'Replaces the AI assistant settings. crash_doctor_enabled is optional and keeps its value when left out.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Settings updated.')]
    public function update(UpdateAssistantSettingsRequest $request): JsonResponse
    {
        AssistantSettings::store($request->settings());

        Activity::event('admin:assistant.settings.update')->log();

        return new JsonResponse(AssistantSettings::all());
    }
}
