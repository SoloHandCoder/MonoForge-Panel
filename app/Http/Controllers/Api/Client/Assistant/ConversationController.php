<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client\Assistant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Assistant\AssistantRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Assistant\DecideToolCallRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Assistant\SendAssistantMessageRequest;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiToolCall;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Assistant\ConversationPresenter;
use Pterodactyl\Services\Assistant\ConversationService;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Server Assistant', 'Chat with the AI assistant about a server. It can only do what your own permissions allow, and asks before sensitive actions.')]
class ConversationController extends ClientApiController
{
    public const array BUSY_ERROR = [
        'errors' => [[
            'code' => 'ConflictHttpException',
            'status' => '409',
            'detail' => 'The assistant is still working on this conversation.',
        ]],
    ];

    public const array NO_BALANCE_ERROR = [
        'errors' => [[
            'code' => 'AssistantUnavailableException',
            'status' => '402',
            'detail' => 'You have used your free AI tokens for this month and your token balance is empty. Top up your wallet to keep using the assistant.',
        ]],
    ];

    public const array NOT_WAITING_ERROR = [
        'errors' => [[
            'code' => 'ConflictHttpException',
            'status' => '409',
            'detail' => 'This action is not waiting for a decision.',
        ]],
    ];

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ConversationPresenter $presenter,
    ) {}

    #[Endpoint('List assistant conversations', 'Returns your own assistant conversations on this server, most recently active first. Other users of the server cannot see them.')]
    #[ScribeResponse(ConversationPresenter::LIST_EXAMPLE, description: 'Conversations returned.')]
    public function index(AssistantRequest $request, Server $server): JsonResponse
    {
        $conversations = AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->where('scope', AiConversation::SCOPE_SERVER)
            ->where('server_id', $server->id)
            ->latest('updated_at')
            ->latest('id')
            ->limit(100)
            ->get();

        return new JsonResponse(['data' => $conversations->map(fn (AiConversation $conversation): array => ConversationPresenter::summary($conversation))->values()->all()]);
    }

    #[Endpoint('Start an assistant conversation', "Starts a conversation with the message and queues the assistant's answer. Poll the conversation while its status is running. Refused with 402 when this month's free AI tokens are used up and the token balance is empty.")]
    #[ScribeResponse(ConversationPresenter::EXAMPLE, description: 'Conversation started.')]
    #[ResponseField('status', 'string', 'The conversation status: idle, running, awaiting_approval or error.', schema: ConversationPresenter::STATUS_SCHEMA)]
    #[ResponseField('messages', 'object[]', 'The messages, oldest first. Tool results are part of their tool call.', schema: ConversationPresenter::MESSAGES_SCHEMA)]
    #[ScribeResponse(self::NO_BALANCE_ERROR, status: 402, description: 'No free AI tokens and no token balance left.')]
    public function store(SendAssistantMessageRequest $request, Server $server): JsonResponse
    {
        $conversation = $this->conversations->start($request->user(), AiConversation::SCOPE_SERVER, $server, $request->message());

        return new JsonResponse($this->presenter->present($conversation));
    }

    #[Endpoint('Get an assistant conversation', "Returns the conversation with its messages, each assistant message's tool calls (label, arguments, status, a preview of the result) and this month's AI usage. Status is idle, running, awaiting_approval (a tool call needs Allow or Deny) or error.")]
    #[UrlParam('conversation_uuid', 'string', 'The conversation UUID.', example: '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93')]
    #[ScribeResponse(ConversationPresenter::EXAMPLE, description: 'Conversation returned.')]
    #[ResponseField('status', 'string', 'The conversation status: idle, running, awaiting_approval or error.', schema: ConversationPresenter::STATUS_SCHEMA)]
    #[ResponseField('messages', 'object[]', 'The messages, oldest first. Tool results are part of their tool call.', schema: ConversationPresenter::MESSAGES_SCHEMA)]
    public function show(AssistantRequest $request, Server $server, AiConversation $conversation): JsonResponse
    {
        $conversation->recoverIfStale();

        return new JsonResponse($this->presenter->present($conversation));
    }

    #[Endpoint('Send an assistant message', "Adds a message to the conversation and queues the assistant's answer. Refused with 409 while the assistant is still working or waiting for a decision.")]
    #[UrlParam('conversation_uuid', 'string', 'The conversation UUID.', example: '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93')]
    #[ScribeResponse(ConversationPresenter::EXAMPLE, description: 'Message sent.')]
    #[ResponseField('status', 'string', 'The conversation status: idle, running, awaiting_approval or error.', schema: ConversationPresenter::STATUS_SCHEMA)]
    #[ResponseField('messages', 'object[]', 'The messages, oldest first. Tool results are part of their tool call.', schema: ConversationPresenter::MESSAGES_SCHEMA)]
    #[ScribeResponse(self::NO_BALANCE_ERROR, status: 402, description: 'No free AI tokens and no token balance left.')]
    #[ScribeResponse(self::BUSY_ERROR, status: 409, description: 'The assistant is still working on this conversation.')]
    public function message(SendAssistantMessageRequest $request, Server $server, AiConversation $conversation): JsonResponse
    {
        return new JsonResponse($this->presenter->present($this->conversations->send($conversation, $request->message())));
    }

    #[Endpoint('Allow an assistant action', 'Allows a tool call that is waiting for confirmation: it runs now with your permissions, and the assistant continues. From a panel session only (API keys get 403).')]
    #[UrlParam('conversation_uuid', 'string', 'The conversation UUID.', example: '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93')]
    #[UrlParam('toolCall_id', 'integer', 'The tool call ID.', example: 2)]
    #[ScribeResponse(ConversationPresenter::EXAMPLE, description: 'Action allowed.')]
    #[ResponseField('status', 'string', 'The conversation status: idle, running, awaiting_approval or error.', schema: ConversationPresenter::STATUS_SCHEMA)]
    #[ResponseField('messages', 'object[]', 'The messages, oldest first. Tool results are part of their tool call.', schema: ConversationPresenter::MESSAGES_SCHEMA)]
    #[ScribeResponse(self::NOT_WAITING_ERROR, status: 409, description: 'The tool call is not waiting for a decision.')]
    public function approve(DecideToolCallRequest $request, Server $server, AiConversation $conversation, AiToolCall $toolCall): JsonResponse
    {
        $conversation = $this->conversations->approve($conversation, $toolCall, $request->user());

        Activity::event('server:assistant.tool')
            ->subject($server)
            ->property(['tool' => $toolCall->name, 'label' => ConversationPresenter::activityLabel($toolCall), 'decision' => 'Allowed'])
            ->log();

        return new JsonResponse($this->presenter->present($conversation));
    }

    #[Endpoint('Deny an assistant action', 'Denies a tool call that is waiting for confirmation: it does not run, the assistant is told so and continues. From a panel session only (API keys get 403).')]
    #[UrlParam('conversation_uuid', 'string', 'The conversation UUID.', example: '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93')]
    #[UrlParam('toolCall_id', 'integer', 'The tool call ID.', example: 2)]
    #[ScribeResponse(ConversationPresenter::EXAMPLE, description: 'Action denied.')]
    #[ResponseField('status', 'string', 'The conversation status: idle, running, awaiting_approval or error.', schema: ConversationPresenter::STATUS_SCHEMA)]
    #[ResponseField('messages', 'object[]', 'The messages, oldest first. Tool results are part of their tool call.', schema: ConversationPresenter::MESSAGES_SCHEMA)]
    #[ScribeResponse(self::NOT_WAITING_ERROR, status: 409, description: 'The tool call is not waiting for a decision.')]
    public function deny(DecideToolCallRequest $request, Server $server, AiConversation $conversation, AiToolCall $toolCall): JsonResponse
    {
        $conversation = $this->conversations->deny($conversation, $toolCall, $request->user());

        Activity::event('server:assistant.tool')
            ->subject($server)
            ->property(['tool' => $toolCall->name, 'label' => ConversationPresenter::activityLabel($toolCall), 'decision' => 'Denied'])
            ->log();

        return new JsonResponse($this->presenter->present($conversation));
    }

    #[Endpoint('Delete an assistant conversation', 'Deletes the conversation and its messages. Usage already recorded stays on the usage report.')]
    #[UrlParam('conversation_uuid', 'string', 'The conversation UUID.', example: '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93')]
    #[ScribeResponse(status: 204, description: 'Conversation deleted.')]
    public function destroy(AssistantRequest $request, Server $server, AiConversation $conversation): Response
    {
        $conversation->delete();

        return $this->returnNoContent();
    }
}
