<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Http\Requests\Api\Client\Support\GetSupportTicketsRequest;
use Pterodactyl\Http\Requests\Api\Client\Support\ReplySupportTicketRequest;
use Pterodactyl\Http\Requests\Api\Client\Support\StoreSupportTicketRequest;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Support\SupportAttachment;
use Pterodactyl\Models\Support\SupportCategory;
use Pterodactyl\Models\Support\SupportTicket;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Support\SupportAttachmentResponse;
use Pterodactyl\Services\Support\SupportPresenter;
use Pterodactyl\Services\Support\SupportTicketService;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Support', "The authenticated user's support tickets. Other users' tickets and staff-only notes are never returned.")]
class SupportTicketController extends ClientApiController
{
    public const array OPTIONS_EXAMPLE = [
        'data' => [
            'categories' => [SupportPresenter::CATEGORY_EXAMPLE],
            'servers' => [SupportPresenter::SERVER_EXAMPLE],
        ],
    ];

    public function __construct(private readonly SupportTicketService $tickets) {}

    #[Endpoint('Get support options', 'Returns the ticket categories, and the servers the authenticated user owns or is a subuser of, which a ticket can be about.')]
    #[ScribeResponse(self::OPTIONS_EXAMPLE, description: 'Options returned.')]
    #[ResponseField('data', 'object', 'The categories and servers.', schema: ['type' => 'object', 'required' => ['categories', 'servers'], 'properties' => ['categories' => ['type' => 'array', 'items' => SupportPresenter::CATEGORY_SCHEMA], 'servers' => ['type' => 'array', 'items' => SupportPresenter::SERVER_SCHEMA]]])]
    public function options(ClientApiRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => [
            'categories' => SupportCategory::ordered()->get()->map(fn (SupportCategory $category): array => SupportPresenter::category($category))->values()->all(),
            'servers' => $this->servers($request->user())->map(fn (Server $server): array => ['identifier' => $server->uuidShort, 'name' => $server->name])->values()->all(),
        ]]);
    }

    #[Endpoint('List support tickets', "Returns the authenticated user's tickets, most recently active first. `unread` is true when staff replied since the user last opened the ticket.")]
    #[QueryParam('page', 'integer', 'Page number to return.', required: false, example: 1)]
    #[QueryParam('per_page', 'integer', 'Results to return per page. The maximum is 100.', required: false, example: 25)]
    #[ScribeResponse(SupportPresenter::LIST_EXAMPLE, description: 'Tickets returned.')]
    #[ResponseField('data', 'object[]', 'The tickets.', schema: SupportPresenter::TICKET_LIST_SCHEMA)]
    #[ResponseField('meta', 'object', 'The page.', schema: SupportPresenter::PAGINATION_SCHEMA)]
    public function index(GetSupportTicketsRequest $request): JsonResponse
    {
        $user = $request->user();
        $page = SupportPresenter::withReadMarker(SupportTicket::query()->select('support_tickets.*'), $user)
            ->with(['category', 'server'])
            ->where('user_id', $user->id)
            ->latest('last_message_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(25));

        return new JsonResponse([
            'data' => collect($page->items())->map(fn (SupportTicket $ticket): array => SupportPresenter::ticket($ticket))->values()->all(),
            'meta' => SupportPresenter::pagination($page),
        ]);
    }

    #[Endpoint('Open a support ticket', 'Opens a ticket with its first message (markdown) and up to 3 PNG, JPEG or WebP images of up to 5 MB each. Limited to 3 tickets an hour.')]
    #[BodyParam('subject', 'string', 'What the ticket is about.', example: 'My server does not start')]
    #[BodyParam('category_id', 'integer', 'The ticket category, from the support options.', example: 1)]
    #[BodyParam('server', 'string', 'The short identifier of a server the user owns or is a subuser of.', required: false, example: '1a7ce997')]
    #[BodyParam('body', 'string', 'The first message, in markdown.', example: 'The server stops right after **Loading world**.')]
    #[BodyParam('attachments', 'file[]', 'Up to 3 images.', required: false)]
    #[ScribeResponse(SupportPresenter::THREAD_EXAMPLE, status: 201, description: 'Ticket opened.')]
    #[ResponseField('data', 'object', 'The ticket and its messages, oldest first.', schema: SupportPresenter::THREAD_SCHEMA)]
    public function store(StoreSupportTicketRequest $request): JsonResponse
    {
        $user = $request->user();
        $server = null;
        if (($identifier = $request->serverIdentifier()) !== null) {
            $server = $this->servers($user)->first(fn (Server $candidate): bool => $candidate->uuidShort === $identifier || $candidate->uuid === $identifier);
            if (! $server instanceof Server) {
                throw ValidationException::withMessages(['server' => 'Choose one of your own servers.']);
            }
        }

        $ticket = $this->tickets->open(
            $user,
            $request->ticketSubject(),
            SupportCategory::query()->findOrFail($request->categoryId()),
            $server,
            $request->messageBody(),
            $request->images(),
        );

        Activity::event('user:support.create')
            ->subject($user)
            ->property(['ticket' => $ticket->id, 'title' => $ticket->subject])
            ->log();

        return new JsonResponse(['data' => $this->present($ticket, $user)], Response::HTTP_CREATED);
    }

    #[Endpoint('Get a support ticket', "Returns one of the authenticated user's tickets with its messages, oldest first, and marks it read.")]
    #[UrlParam('ticket', 'integer', 'The support ticket ID.', example: 12)]
    #[ScribeResponse(SupportPresenter::THREAD_EXAMPLE, description: 'Ticket returned.')]
    #[ResponseField('data', 'object', 'The ticket and its messages, oldest first.', schema: SupportPresenter::THREAD_SCHEMA)]
    public function show(ClientApiRequest $request, int $ticket): JsonResponse
    {
        $user = $request->user();

        return new JsonResponse(['data' => $this->present($this->own($user, $ticket), $user)]);
    }

    #[Endpoint('Reply to a support ticket', "Adds a message (markdown, up to 3 images) to one of the authenticated user's tickets. A ticket closed less than 7 days ago reopens; an older closed ticket is refused with 409.")]
    #[UrlParam('ticket', 'integer', 'The support ticket ID.', example: 12)]
    #[BodyParam('body', 'string', 'The message, in markdown.', example: 'It happens again after the restart.')]
    #[BodyParam('attachments', 'file[]', 'Up to 3 images.', required: false)]
    #[ScribeResponse(SupportPresenter::THREAD_EXAMPLE, status: 201, description: 'Reply added.')]
    #[ScribeResponse(['errors' => [['code' => 'ConflictHttpException', 'status' => '409', 'detail' => 'This ticket was closed more than 7 days ago. Open a new ticket instead.']]], status: 409, description: 'The ticket was closed too long ago to reopen.')]
    #[ResponseField('data', 'object', 'The ticket and its messages, oldest first.', schema: SupportPresenter::THREAD_SCHEMA)]
    public function reply(ReplySupportTicketRequest $request, int $ticket): JsonResponse
    {
        $user = $request->user();
        $model = $this->own($user, $ticket);

        $this->tickets->replyAsUser($model, $user, $request->messageBody(), $request->images());

        Activity::event('user:support.reply')
            ->subject($user)
            ->property(['ticket' => $model->id])
            ->log();

        return new JsonResponse(['data' => $this->present($model->refresh(), $user)], Response::HTTP_CREATED);
    }

    #[Endpoint('Close a support ticket', "Closes one of the authenticated user's tickets. Replying within 7 days reopens it.")]
    #[UrlParam('ticket', 'integer', 'The support ticket ID.', example: 12)]
    #[ScribeResponse(SupportPresenter::THREAD_EXAMPLE, description: 'Ticket closed.')]
    #[ResponseField('data', 'object', 'The ticket and its messages, oldest first.', schema: SupportPresenter::THREAD_SCHEMA)]
    public function close(ClientApiRequest $request, int $ticket): JsonResponse
    {
        $user = $request->user();
        $model = $this->tickets->close($this->own($user, $ticket));

        Activity::event('user:support.close')
            ->subject($user)
            ->property(['ticket' => $model->id])
            ->log();

        return new JsonResponse(['data' => $this->present($model, $user)]);
    }

    #[Endpoint('Get a support attachment', "Returns an image attached to a message on one of the authenticated user's tickets.")]
    #[UrlParam('ticket', 'integer', 'The support ticket ID.', example: 12)]
    #[UrlParam('attachment', 'integer', 'The attachment ID.', example: 3)]
    #[ScribeResponse('', description: 'Image returned.')]
    public function attachment(ClientApiRequest $request, int $ticket, int $attachment): Response
    {
        $model = $this->own($request->user(), $ticket);

        // Only images on messages the player can see: never one on an internal note.
        $file = SupportAttachment::query()
            ->whereKey($attachment)
            ->whereHas('message', fn (Builder $query): Builder => $query->where('ticket_id', $model->id)->where('is_internal', false))
            ->firstOrFail();

        return SupportAttachmentResponse::make($file);
    }

    /**
     * The ticket, if it is the user's own; anyone else's is not found, so its existence stays hidden.
     */
    private function own(User $user, int $ticket): SupportTicket
    {
        return SupportTicket::query()->where('user_id', $user->id)->findOrFail($ticket);
    }

    /**
     * Marks the ticket read for the user and returns its thread.
     *
     * @return array<string, mixed>
     */
    private function present(SupportTicket $ticket, User $user): array
    {
        $this->tickets->markRead($ticket, $user);
        $ticket->setAttribute('read_message_id', $ticket->last_message_id);
        $ticket->load(['category', 'server']);

        return SupportPresenter::thread($ticket);
    }

    /**
     * The servers the user owns or is a subuser of; root administrators get no extra servers here.
     *
     * @return Collection<int, Server>
     */
    private function servers(User $user): Collection
    {
        return $user->accessibleServers()->orderBy('servers.name')->limit(500)->get()->toBase();
    }
}
