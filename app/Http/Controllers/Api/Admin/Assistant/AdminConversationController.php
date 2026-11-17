<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Assistant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Controllers\Api\Client\Assistant\ConversationController;
use Pterodactyl\Http\Requests\Api\Admin\Assistant\AdminAssistantRequest;
use Pterodactyl\Http\Requests\Api\Admin\Assistant\ApproveAdminToolCallRequest;
use Pterodactyl\Http\Requests\Api\Admin\Assistant\SendAdminAssistantMessageRequest;
use Pterodactyl\Http\Requests\Api\Admin\Assistant\SetAdminApprovalModeRequest;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiToolCall;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Assistant\ConversationPresenter;
use Pterodactyl\Services\Assistant\ConversationService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('AI Assistant', 'The admin AI assistant, its providers, settings and usage. Root administrators only, from a panel session.')]
class AdminConversationController extends AdminApiController
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ConversationPresenter $presenter,
    ) {}

    #[Endpoint('List admin assistant conversations', 'Returns your own admin assistant conversations, most recently active first.')]
    #[ScribeResponse(ConversationPresenter::LIST_EXAMPLE, description: 'Conversations returned.')]
    public function index(AdminAssistantRequest $request): JsonResponse
    {
        $conversations = AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->where('scope', AiConversation::SCOPE_ADMIN)
            ->latest('updated_at')
            ->latest('id')
            ->limit(100)
            ->get();

        return new JsonResponse(['data' => $conversations->map(fn (AiConversation $conversation): array => ConversationPresenter::summary($conversation))->values()->all()]);
    }

    #[Endpoint('Start an admin assistant conversation', "Starts a conversation with the message and queues the assistant's answer. Poll the conversation while its status is running. The admin assistant is never charged.")]
    #[ScribeResponse(ConversationPresenter::EXAMPLE, description: 'Conversation started.')]
    #[ResponseField('status', 'string', 'The conversation status: idle, running, awaiting_approval or error.', schema: ConversationPresenter::STATUS_SCHEMA)]
    #[ResponseField('messages', 'object[]', 'The messages, oldest first. Tool results are part of their tool call.', schema: ConversationPresenter::MESSAGES_SCHEMA)]
    public function store(SendAdminAssistantMessageRequest $request): JsonResponse
    {
        $conversation = $this->conversations->start($request->user(), AiConversation::SCOPE_ADMIN, null, $request->message());

        return new JsonResponse($this->presenter->present($conversation));
    }

    #[Endpoint('Get an admin assistant conversation', "Returns the conversation with its messages and each assistant message's tool calls. Status is idle, running, awaiting_approval (a tool call needs Allow or Deny) or error.")]
    #[UrlParam('conversation_uuid', 'string', 'The conversation UUID.', example: '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93')]
    #[ScribeResponse(ConversationPresenter::EXAMPLE, description: 'Conversation returned.')]
    #[ResponseField('status', 'string', 'The conversation status: idle, running, awaiting_approval or error.', schema: ConversationPresenter::STATUS_SCHEMA)]
    #[ResponseField('messages', 'object[]', 'The messages, oldest first. Tool results are part of their tool call.', schema: ConversationPresenter::MESSAGES_SCHEMA)]
    public function show(AdminAssistantRequest $request, AiConversation $conversation): JsonResponse
    {
        $this->assertOwn($conversation, $request->user());
        $conversation->recoverIfStale();

        return new JsonResponse($this->presenter->present($conversation));
    }

    #[Endpoint('Send an admin assistant message', "Adds a message to the conversation and queues the assistant's answer. Refused with 409 while the assistant is still working or waiting for a decision.")]
    #[UrlParam('conversation_uuid', 'string', 'The conversation UUID.', example: '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93')]
    #[ScribeResponse(ConversationPresenter::EXAMPLE, description: 'Message sent.')]
    #[ResponseField('status', 'string', 'The conversation status: idle, running, awaiting_approval or error.', schema: ConversationPresenter::STATUS_SCHEMA)]
    #[ResponseField('messages', 'object[]', 'The messages, oldest first. Tool results are part of their tool call.', schema: ConversationPresenter::MESSAGES_SCHEMA)]
    #[ScribeResponse(ConversationController::BUSY_ERROR, status: 409, description: 'The assistant is still working on this conversation.')]
    public function message(SendAdminAssistantMessageRequest $request, AiConversation $conversation): JsonResponse
    {
        $this->assertOwn($conversation, $request->user());

        return new JsonResponse($this->presenter->present($this->conversations->send($conversation, $request->message())));
    }

    #[Endpoint('Allow an admin assistant action', 'Allows a tool call that is waiting for confirmation: it runs now with your permissions, and the assistant continues. With until_next_message, the further actions of this turn that need confirmation are allowed too, until you send your next message (actions that always ask, such as token adjustments and suspensions, still wait).')]
    #[UrlParam('conversation_uuid', 'string', 'The conversation UUID.', example: '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93')]
    #[UrlParam('toolCall_id', 'integer', 'The tool call ID.', example: 2)]
    #[BodyParam('until_next_message', 'boolean', 'Also allow the further actions of this turn until your next message.', required: false, example: false)]
    #[ScribeResponse(ConversationPresenter::EXAMPLE, description: 'Action allowed.')]
    #[ResponseField('status', 'string', 'The conversation status: idle, running, awaiting_approval or error.', schema: ConversationPresenter::STATUS_SCHEMA)]
    #[ResponseField('messages', 'object[]', 'The messages, oldest first. Tool results are part of their tool call.', schema: ConversationPresenter::MESSAGES_SCHEMA)]
    #[ScribeResponse(ConversationController::NOT_WAITING_ERROR, status: 409, description: 'The tool call is not waiting for a decision.')]
    public function approve(ApproveAdminToolCallRequest $request, AiConversation $conversation, AiToolCall $toolCall): JsonResponse
    {
        $this->assertOwn($conversation, $request->user(), $toolCall);
        $conversation = $this->conversations->approve($conversation, $toolCall, $request->user(), $request->untilNextMessage());

        Activity::event('admin:assistant.tool')
            ->property(['tool' => $toolCall->name, 'label' => ConversationPresenter::activityLabel($toolCall), 'decision' => $request->untilNextMessage() ? 'Allowed (until the next message)' : 'Allowed'])
            ->log();

        return new JsonResponse($this->presenter->present($conversation));
    }

    #[Endpoint('Deny an admin assistant action', 'Denies a tool call that is waiting for confirmation: it does not run, the assistant is told so and continues.')]
    #[UrlParam('conversation_uuid', 'string', 'The conversation UUID.', example: '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93')]
    #[UrlParam('toolCall_id', 'integer', 'The tool call ID.', example: 2)]
    #[ScribeResponse(ConversationPresenter::EXAMPLE, description: 'Action denied.')]
    #[ResponseField('status', 'string', 'The conversation status: idle, running, awaiting_approval or error.', schema: ConversationPresenter::STATUS_SCHEMA)]
    #[ResponseField('messages', 'object[]', 'The messages, oldest first. Tool results are part of their tool call.', schema: ConversationPresenter::MESSAGES_SCHEMA)]
    #[ScribeResponse(ConversationController::NOT_WAITING_ERROR, status: 409, description: 'The tool call is not waiting for a decision.')]
    public function deny(AdminAssistantRequest $request, AiConversation $conversation, AiToolCall $toolCall): JsonResponse
    {
        $this->assertOwn($conversation, $request->user(), $toolCall);
        $conversation = $this->conversations->deny($conversation, $toolCall, $request->user());

        Activity::event('admin:assistant.tool')
            ->property(['tool' => $toolCall->name, 'label' => ConversationPresenter::activityLabel($toolCall), 'decision' => 'Denied'])
            ->log();

        return new JsonResponse($this->presenter->present($conversation));
    }

    #[Endpoint('Stop the admin assistant', 'Stops the turn the assistant is working on or waiting with: no further AI provider call or action starts, actions that have not started never run, and "Stopped by you." is added to the conversation. Stopping a conversation that is not working changes nothing.')]
    #[UrlParam('conversation_uuid', 'string', 'The conversation UUID.', example: '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93')]
    #[ScribeResponse(ConversationPresenter::EXAMPLE, description: 'Assistant stopped.')]
    #[ResponseField('status', 'string', 'The conversation status: idle, running, awaiting_approval or error.', schema: ConversationPresenter::STATUS_SCHEMA)]
    #[ResponseField('messages', 'object[]', 'The messages, oldest first. Tool results are part of their tool call.', schema: ConversationPresenter::MESSAGES_SCHEMA)]
    public function stop(AdminAssistantRequest $request, AiConversation $conversation): JsonResponse
    {
        $this->assertOwn($conversation, $request->user());

        if ($this->conversations->stop($conversation)) {
            Activity::event('admin:assistant.stop')->log();
        }

        return new JsonResponse($this->presenter->present($conversation));
    }

    #[Endpoint('Set the admin assistant approval mode', 'Sets how the conversation handles actions that need confirmation: ask (the default) or allow_everything (allow them without asking, except actions that always ask: token adjustments, suspensions and announcements). allow_everything needs confirmed: true and is refused with 422 when it is turned off in the settings.')]
    #[UrlParam('conversation_uuid', 'string', 'The conversation UUID.', example: '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93')]
    #[BodyParam('mode', 'string', 'ask or allow_everything.', required: true, example: 'allow_everything', enum: AiConversation::APPROVAL_MODES)]
    #[BodyParam('confirmed', 'boolean', 'Whether the admin confirmed the warning; required for allow_everything.', required: false, example: true)]
    #[ScribeResponse(ConversationPresenter::EXAMPLE, description: 'Approval mode set.')]
    #[ResponseField('approval_mode', 'string', 'ask or allow_everything.', schema: ConversationPresenter::APPROVAL_MODE_SCHEMA)]
    #[ResponseField('messages', 'object[]', 'The messages, oldest first. Tool results are part of their tool call.', schema: ConversationPresenter::MESSAGES_SCHEMA)]
    #[ScribeResponse(ConversationController::CONFIRM_ERROR, status: 422, description: 'allow_everything without confirmed, or turned off in the settings.')]
    public function approvalMode(SetAdminApprovalModeRequest $request, AiConversation $conversation): JsonResponse
    {
        $this->assertOwn($conversation, $request->user());

        if ($this->conversations->setApprovalMode($conversation, $request->mode(), $request->confirmed())) {
            Activity::event('admin:assistant.approval-mode')
                ->property(['mode' => ConversationPresenter::approvalModeLabel($request->mode())])
                ->log();
        }

        return new JsonResponse($this->presenter->present($conversation));
    }

    #[Endpoint('Delete an admin assistant conversation', 'Deletes the conversation and its messages. Usage already recorded stays on the usage report.')]
    #[UrlParam('conversation_uuid', 'string', 'The conversation UUID.', example: '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93')]
    #[ScribeResponse(status: 204, description: 'Conversation deleted.')]
    public function destroy(AdminAssistantRequest $request, AiConversation $conversation): Response
    {
        $this->assertOwn($conversation, $request->user());
        $conversation->delete();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /** Admins only ever see their own admin conversations; anything else does not exist for them. */
    private function assertOwn(AiConversation $conversation, User $user, ?AiToolCall $call = null): void
    {
        $own = $conversation->user_id === $user->id
            && $conversation->scope === AiConversation::SCOPE_ADMIN
            && (! $call instanceof AiToolCall || $call->conversation_id === $conversation->id);

        throw_unless($own, NotFoundHttpException::class, 'The requested resource could not be found.');
    }
}
