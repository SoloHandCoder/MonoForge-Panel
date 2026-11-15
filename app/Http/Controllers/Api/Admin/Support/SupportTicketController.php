<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Enum\SupportTicketStatus;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Support\GetSupportTicketsRequest;
use Pterodactyl\Http\Requests\Api\Admin\Support\StaffReplyRequest;
use Pterodactyl\Http\Requests\Api\Admin\Support\SupportReadRequest;
use Pterodactyl\Http\Requests\Api\Admin\Support\UpdateSupportTicketRequest;
use Pterodactyl\Models\Support\SupportAttachment;
use Pterodactyl\Models\Support\SupportCategory;
use Pterodactyl\Models\Support\SupportTicket;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Support\SupportAttachmentResponse;
use Pterodactyl\Services\Support\SupportPresenter;
use Pterodactyl\Services\Support\SupportTicketService;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Support', 'The support ticket queue: tickets, replies, internal notes, assignment, canned replies and categories.')]
class SupportTicketController extends AdminApiController
{
    public const array OPTIONS_EXAMPLE = [
        'data' => [
            'categories' => [SupportPresenter::CATEGORY_EXAMPLE],
            'staff' => [SupportPresenter::STAFF_MEMBER_EXAMPLE],
        ],
    ];

    public function __construct(private readonly SupportTicketService $tickets) {}

    #[Endpoint('Get the support summary', 'Returns how many tickets wait on you: open or waiting on staff, unassigned or assigned to you, with a player message you have not opened yet. The sidebar shows it as a badge.')]
    #[ScribeResponse(['data' => ['unread' => 3]], description: 'Summary returned.')]
    #[ResponseField('data', 'object', 'The summary.', schema: ['type' => 'object', 'required' => ['unread'], 'properties' => ['unread' => ['type' => 'integer', 'description' => 'Tickets waiting on you.']]])]
    public function summary(SupportReadRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => ['unread' => SupportPresenter::unreadForStaff($request->user())->count()]]);
    }

    #[Endpoint('Get support options', 'Returns the ticket categories and the staff members (root administrators) a ticket can be assigned to.')]
    #[ScribeResponse(self::OPTIONS_EXAMPLE, description: 'Options returned.')]
    #[ResponseField('data', 'object', 'The categories and staff members.', schema: ['type' => 'object', 'required' => ['categories', 'staff'], 'properties' => ['categories' => ['type' => 'array', 'items' => SupportPresenter::CATEGORY_SCHEMA], 'staff' => ['type' => 'array', 'items' => SupportPresenter::STAFF_MEMBER_SCHEMA]]])]
    public function options(SupportReadRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => [
            'categories' => SupportCategory::ordered()->get()->map(fn (SupportCategory $category): array => SupportPresenter::category($category))->values()->all(),
            'staff' => User::query()->where('root_admin', true)->orderBy('username')->limit(200)->get()
                ->map(fn (User $user): array => SupportPresenter::staffMember($user))->values()->all(),
        ]]);
    }

    #[Endpoint('List support tickets', 'Returns tickets, most recently active first, with filters. `unread` is true when the player wrote since you last opened the ticket.')]
    #[QueryParam('status', 'string', 'Only tickets with this status; `active` means every status but closed.', required: false, example: 'active', enum: ['active', 'open', 'answered', 'waiting_staff', 'closed'])]
    #[QueryParam('category', 'integer', 'Only tickets in this category.', required: false, example: 1)]
    #[QueryParam('assigned', 'string', 'Only tickets assigned to you (`me`), to nobody (`none`), or to the staff member with this ID.', required: false, example: 'me')]
    #[QueryParam('search', 'string', 'Matches the ticket number, the subject, or the username or email of the player.', required: false, example: 'start')]
    #[QueryParam('page', 'integer', 'Page number to return.', required: false, example: 1)]
    #[QueryParam('per_page', 'integer', 'Results to return per page. The maximum is 100.', required: false, example: 25)]
    #[ScribeResponse(SupportPresenter::STAFF_LIST_EXAMPLE, description: 'Tickets returned.')]
    #[ResponseField('data', 'object[]', 'The tickets.', schema: SupportPresenter::STAFF_TICKET_LIST_SCHEMA)]
    #[ResponseField('meta', 'object', 'The page.', schema: SupportPresenter::PAGINATION_SCHEMA)]
    public function index(GetSupportTicketsRequest $request): JsonResponse
    {
        $staff = $request->user();
        $query = SupportPresenter::withReadMarker(SupportTicket::query()->select('support_tickets.*'), $staff)
            ->with(['category', 'server', 'user', 'assignee']);

        $status = $request->string('status')->toString();
        if ($status === 'active') {
            $query->where('status', '!=', SupportTicketStatus::Closed->value);
        } elseif ($status !== '') {
            $query->where('status', $status);
        }

        if ($request->filled('category')) {
            $query->where('category_id', $request->integer('category'));
        }

        $assigned = $request->string('assigned')->toString();
        match ($assigned) {
            '' => null,
            'me' => $query->where('assigned_to', $staff->id),
            'none' => $query->whereNull('assigned_to'),
            default => $query->where('assigned_to', (int) $assigned),
        };

        $search = mb_trim($request->string('search')->toString());
        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(function (Builder $builder) use ($search, $like): void {
                $builder->where('subject', 'like', $like)
                    ->orWhereHas('user', fn (Builder $user) => $user->where('username', 'like', $like)->orWhere('email', 'like', $like));

                if (preg_match('/^#?(\d{1,10})$/', $search, $match) === 1) {
                    $builder->orWhere('support_tickets.id', (int) $match[1]);
                }
            });
        }

        $page = $query->latest('last_message_at')->orderByDesc('id')->paginate($request->perPage(25));

        return new JsonResponse([
            'data' => collect($page->items())->map(fn (SupportTicket $ticket): array => SupportPresenter::staffTicket($ticket))->values()->all(),
            'meta' => SupportPresenter::pagination($page),
        ]);
    }

    #[Endpoint('Get a support ticket', 'Returns a ticket with every message, internal notes included, oldest first, and marks it read for you.')]
    #[UrlParam('ticket', 'integer', 'The support ticket ID.', example: 12)]
    #[ScribeResponse(SupportPresenter::STAFF_THREAD_EXAMPLE, description: 'Ticket returned.')]
    #[ResponseField('data', 'object', 'The ticket and its messages, oldest first.', schema: SupportPresenter::STAFF_THREAD_SCHEMA)]
    public function show(SupportReadRequest $request, int $ticket): JsonResponse
    {
        return new JsonResponse(['data' => $this->present(SupportTicket::query()->findOrFail($ticket), $request->user())]);
    }

    #[Endpoint('Reply to a support ticket', 'Adds a staff reply, which marks the ticket answered and tells the player in the panel and by email, or with `internal` a note only staff see. Up to 3 images.')]
    #[UrlParam('ticket', 'integer', 'The support ticket ID.', example: 12)]
    #[BodyParam('body', 'string', 'The message, in markdown.', example: 'Your world folder was renamed; we restored it.')]
    #[BodyParam('internal', 'boolean', 'An internal note only staff see. It does not change the status or notify the player.', required: false, example: false)]
    #[BodyParam('attachments', 'file[]', 'Up to 3 images.', required: false)]
    #[ScribeResponse(SupportPresenter::STAFF_THREAD_EXAMPLE, status: 201, description: 'Reply added.')]
    #[ResponseField('data', 'object', 'The ticket and its messages, oldest first.', schema: SupportPresenter::STAFF_THREAD_SCHEMA)]
    public function reply(StaffReplyRequest $request, int $ticket): JsonResponse
    {
        $model = SupportTicket::query()->findOrFail($ticket);
        $internal = $request->internal();

        $this->tickets->replyAsStaff($model, $request->user(), $request->messageBody(), $internal, $request->images());

        Activity::event($internal ? 'admin:support.note' : 'admin:support.reply')
            ->property(['ticket' => $model->id])
            ->log();

        return new JsonResponse(['data' => $this->present($model->refresh(), $request->user())], Response::HTTP_CREATED);
    }

    #[Endpoint('Update a support ticket', 'Changes the status, the assigned staff member or the category of a ticket. Omitted fields stay as they are.')]
    #[UrlParam('ticket', 'integer', 'The support ticket ID.', example: 12)]
    #[BodyParam('status', 'string', 'The new status.', required: false, example: 'closed', enum: ['open', 'answered', 'waiting_staff', 'closed'])]
    #[BodyParam('assigned_to', 'integer', 'The ID of the root administrator to assign, or null to unassign.', required: false, example: 1, nullable: true)]
    #[BodyParam('category_id', 'integer', 'The category ID, or null for none.', required: false, example: 1, nullable: true)]
    #[ScribeResponse(SupportPresenter::STAFF_THREAD_EXAMPLE, description: 'Ticket updated.')]
    #[ResponseField('data', 'object', 'The ticket and its messages, oldest first.', schema: SupportPresenter::STAFF_THREAD_SCHEMA)]
    public function update(UpdateSupportTicketRequest $request, int $ticket): JsonResponse
    {
        $model = SupportTicket::query()->findOrFail($ticket);

        if ($request->has('assigned_to')) {
            $model->assigned_to = $request->filled('assigned_to') ? $request->integer('assigned_to') : null;
        }

        if ($request->has('category_id')) {
            $model->category_id = $request->filled('category_id') ? $request->integer('category_id') : null;
        }

        $model->save();

        if ($request->filled('status')) {
            $this->tickets->setStatus($model, SupportTicketStatus::from($request->string('status')->toString()));
        }

        Activity::event('admin:support.update')
            ->property(['ticket' => $model->id])
            ->log();

        return new JsonResponse(['data' => $this->present($model->refresh(), $request->user())]);
    }

    #[Endpoint('Get a support attachment', 'Returns an image attached to a message or an internal note on the ticket.')]
    #[UrlParam('ticket', 'integer', 'The support ticket ID.', example: 12)]
    #[UrlParam('attachment', 'integer', 'The attachment ID.', example: 3)]
    #[ScribeResponse('', description: 'Image returned.')]
    public function attachment(SupportReadRequest $request, int $ticket, int $attachment): Response
    {
        $file = SupportAttachment::query()
            ->whereKey($attachment)
            ->whereHas('message', fn ($query) => $query->where('ticket_id', $ticket))
            ->firstOrFail();

        return SupportAttachmentResponse::make($file);
    }

    /**
     * Marks the ticket read for the staff member and returns its full thread.
     *
     * @return array<string, mixed>
     */
    private function present(SupportTicket $ticket, User $staff): array
    {
        $this->tickets->markRead($ticket, $staff);
        $ticket->setAttribute('read_message_id', $ticket->last_message_id);
        $ticket->load(['category', 'server', 'user', 'assignee']);

        return SupportPresenter::staffThread($ticket);
    }
}
