<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Exceptions\Assistant\AssistantUnavailableException;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Client\Assistant\ConversationController;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Assistant\AssistantRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\CrashDoctor\DiagnoseCrashRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\CrashDoctor\ReportCrashRequest;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerCrash;
use Pterodactyl\Services\Assistant\AssistantSettings;
use Pterodactyl\Services\Assistant\ConversationPresenter;
use Pterodactyl\Services\Assistant\CrashDoctor\CrashDiagnosis;
use Pterodactyl\Services\Assistant\CrashDoctor\CrashRecorder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Server Crash Doctor', 'The AI crash doctor: notices that a server crashed or failed to start, and diagnoses it with the assistant. Needs the assistant.use permission; billed like any assistant conversation.')]
class CrashDoctorController extends ClientApiController
{
    public const array EXAMPLE = [
        'enabled' => true,
        'crash' => [
            'id' => 3,
            'kind' => 'crashed',
            'status' => 'open',
            'detected_at' => '2026-11-15T12:00:00+00:00',
            'conversation_uuid' => null,
        ],
    ];

    public const array CRASH_SCHEMA = [
        'type' => 'object',
        'nullable' => true,
        'description' => 'The newest crash of the last 24 hours that was not dismissed, or null.',
        'required' => ['id', 'kind', 'status', 'detected_at', 'conversation_uuid'],
        'properties' => [
            'id' => ['type' => 'integer', 'example' => 3],
            'kind' => ['type' => 'string', 'enum' => [ServerCrash::KIND_CRASHED, ServerCrash::KIND_START_FAILED], 'example' => 'crashed'],
            'status' => ['type' => 'string', 'enum' => [ServerCrash::STATUS_OPEN, ServerCrash::STATUS_DIAGNOSING], 'example' => 'open'],
            'detected_at' => ['type' => 'string', 'example' => '2026-11-15T12:00:00+00:00'],
            'conversation_uuid' => ['type' => 'string', 'nullable' => true, 'description' => 'The crash doctor conversation, once one was started.', 'example' => null],
        ],
    ];

    private const array OFF_ERROR = [
        'errors' => [[
            'code' => 'AssistantUnavailableException',
            'status' => '403',
            'detail' => 'The AI crash doctor is turned off.',
        ]],
    ];

    public function __construct(
        private readonly CrashRecorder $recorder,
        private readonly CrashDiagnosis $diagnosis,
        private readonly ConversationPresenter $presenter,
    ) {}

    #[Endpoint('Get crash doctor status', 'Returns whether the AI crash doctor can be used on this server (it needs the assistant and the crash doctor switched on), and the newest crash of the last 24 hours that was not dismissed. The server page offers the doctor while that crash is open.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Crash doctor status returned.')]
    #[ResponseField('enabled', 'boolean', 'Whether the crash doctor can be used. When false, crash is always null.')]
    #[ResponseField('crash', 'object', 'The newest crash of the last 24 hours that was not dismissed, or null.', schema: self::CRASH_SCHEMA)]
    public function show(AssistantRequest $request, Server $server): JsonResponse
    {
        return new JsonResponse($this->status($server));
    }

    #[Endpoint('Report a crash from the console', 'The console saw the server go from running or starting to offline without stopping first. Records the crash (with the last console lines, masked) unless a stop, restart or kill was asked for through the panel in the last two minutes or the server crashed less than ten minutes ago, and returns the crash doctor status. Rate limited to 6 requests a minute.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Crash recorded, or already known; status returned.')]
    #[ResponseField('enabled', 'boolean', 'Whether the crash doctor can be used.')]
    #[ResponseField('crash', 'object', 'The newest crash of the last 24 hours that was not dismissed, or null.', schema: self::CRASH_SCHEMA)]
    #[ScribeResponse(self::OFF_ERROR, status: 403, description: 'The crash doctor or the assistant is turned off.')]
    public function report(ReportCrashRequest $request, Server $server): JsonResponse
    {
        throw_unless(AssistantSettings::crashDoctorAvailable(), AssistantUnavailableException::class, 'The AI crash doctor is turned off.');

        if ($server->status === null && ! $this->recorder->stopRequestedSince($server, CarbonImmutable::now()->subMinutes(2))) {
            $this->recorder->record($server, $request->kind(), ServerCrash::SOURCE_CONSOLE);
        }

        return new JsonResponse($this->status($server));
    }

    #[Endpoint('Diagnose with the crash doctor', "Starts an assistant conversation in crash doctor mode: it reads the console, the newest file in crash-reports/ and the end of logs/latest.log (masked, and only what your permissions allow), then explains what happened and the likely cause, and offers fixes as tool calls you allow or deny. Give crash_id to diagnose a crash from the status, or null to check the server without one. Billed like any assistant conversation; refused with 402 when this month's free AI tokens are used up and the token balance is empty.")]
    #[ScribeResponse(ConversationPresenter::EXAMPLE, description: 'Diagnosis started.')]
    #[ResponseField('status', 'string', 'The conversation status: idle, running, awaiting_approval or error.', schema: ConversationPresenter::STATUS_SCHEMA)]
    #[ResponseField('mode', 'string', 'The conversation mode: crash_doctor.', schema: ConversationPresenter::MODE_SCHEMA)]
    #[ResponseField('messages', 'object[]', 'The messages, oldest first. Tool results are part of their tool call.', schema: ConversationPresenter::MESSAGES_SCHEMA)]
    #[ScribeResponse(ConversationController::NO_BALANCE_ERROR, status: 402, description: 'No free AI tokens and no token balance left.')]
    #[ScribeResponse(self::OFF_ERROR, status: 403, description: 'The crash doctor or the assistant is turned off.')]
    #[ScribeResponse(ConversationController::BUSY_ERROR, status: 409, description: 'The assistant is already working on another of your conversations.')]
    public function diagnose(DiagnoseCrashRequest $request, Server $server): JsonResponse
    {
        $crash = null;
        if ($request->crashId() !== null) {
            $crash = ServerCrash::query()->where('server_id', $server->id)->find($request->crashId())
                ?? throw new NotFoundHttpException('The requested resource was not found for this server.');
        }

        $conversation = $this->diagnosis->start($request->user(), $server, $crash);

        Activity::event('server:crash-doctor.diagnose')
            ->subject($server)
            ->property(['kind' => $crash instanceof ServerCrash ? $crash->kind : 'manual'])
            ->log();

        return new JsonResponse($this->presenter->present($conversation));
    }

    #[Endpoint('Dismiss a crash', 'Hides the crash from the server page; the crash doctor is not offered for it any more.')]
    #[UrlParam('crash_id', 'integer', 'The server crash ID.', example: 3)]
    #[ScribeResponse(status: 204, description: 'Crash dismissed.')]
    public function dismiss(AssistantRequest $request, Server $server, ServerCrash $crash): Response
    {
        $crash->forceFill(['status' => ServerCrash::STATUS_DISMISSED])->save();

        return $this->returnNoContent();
    }

    /**
     * @return array{enabled: bool, crash: array{id: int, kind: string, status: string, detected_at: string, conversation_uuid: string|null}|null}
     */
    private function status(Server $server): array
    {
        if (! AssistantSettings::crashDoctorAvailable()) {
            return ['enabled' => false, 'crash' => null];
        }

        $crash = $this->recorder->latest($server);

        return [
            'enabled' => true,
            'crash' => $crash instanceof ServerCrash ? [
                'id' => $crash->id,
                'kind' => $crash->kind,
                'status' => $crash->status,
                'detected_at' => $crash->detected_at->toAtomString(),
                'conversation_uuid' => $crash->conversation?->uuid,
            ] : null,
        ];
    }
}
