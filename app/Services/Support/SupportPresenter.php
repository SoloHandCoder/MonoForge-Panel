<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Support\SupportAttachment;
use Pterodactyl\Models\Support\SupportCannedReply;
use Pterodactyl\Models\Support\SupportCategory;
use Pterodactyl\Models\Support\SupportMessage;
use Pterodactyl\Models\Support\SupportTicket;
use Pterodactyl\Models\User;

/**
 * The JSON shapes of the support endpoints, with the examples the API documentation shows.
 * Players get `ticket()`/`thread()`, which never include internal notes, the assignee or the
 * owner's email; staff get `staffTicket()`/`staffThread()`.
 */
class SupportPresenter
{
    public const array CATEGORY_EXAMPLE = ['id' => 1, 'name' => 'Billing'];

    public const array SERVER_EXAMPLE = ['identifier' => '1a7ce997', 'name' => 'Survival'];

    public const array ATTACHMENT_EXAMPLE = ['id' => 3, 'name' => 'crash.png', 'mime' => 'image/png', 'size' => 48213, 'width' => 1280, 'height' => 720];

    public const array TICKET_EXAMPLE = [
        'id' => 12,
        'subject' => 'My server does not start',
        'status' => 'answered',
        'category' => self::CATEGORY_EXAMPLE,
        'server' => self::SERVER_EXAMPLE,
        'unread' => true,
        'can_reply' => true,
        'last_message_at' => '2026-11-15T12:30:00+00:00',
        'last_message_by_staff' => true,
        'closed_at' => '2026-11-15T13:00:00+00:00',
        'created_at' => '2026-11-15T12:00:00+00:00',
    ];

    public const array MESSAGE_EXAMPLE = [
        'id' => 40,
        'body' => 'The server stops right after **Loading world**.',
        'author' => 'player1',
        'is_staff' => false,
        'attachments' => [self::ATTACHMENT_EXAMPLE],
        'created_at' => '2026-11-15T12:00:00+00:00',
    ];

    public const array STAFF_MEMBER_EXAMPLE = ['id' => 1, 'username' => 'admin'];

    public const array STAFF_TICKET_EXAMPLE = [
        ...self::TICKET_EXAMPLE,
        'unread' => false,
        'user' => ['id' => 5, 'username' => 'player1', 'email' => 'player1@example.com'],
        'assignee' => self::STAFF_MEMBER_EXAMPLE,
    ];

    public const array STAFF_MESSAGE_EXAMPLE = [...self::MESSAGE_EXAMPLE, 'is_internal' => false];

    public const array PAGINATION_EXAMPLE = ['current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 1];

    public const array LIST_EXAMPLE = ['data' => [self::TICKET_EXAMPLE], 'meta' => self::PAGINATION_EXAMPLE];

    public const array THREAD_EXAMPLE = ['data' => [...self::TICKET_EXAMPLE, 'messages' => [self::MESSAGE_EXAMPLE]]];

    public const array STAFF_LIST_EXAMPLE = ['data' => [self::STAFF_TICKET_EXAMPLE], 'meta' => self::PAGINATION_EXAMPLE];

    public const array STAFF_THREAD_EXAMPLE = ['data' => [...self::STAFF_TICKET_EXAMPLE, 'messages' => [self::STAFF_MESSAGE_EXAMPLE]]];

    public const array CANNED_REPLY_EXAMPLE = ['id' => 1, 'title' => 'Restart first', 'body' => 'Please restart the server and tell us if it happens again.'];

    public const array CATEGORY_SCHEMA = ['type' => 'object', 'required' => ['id', 'name'], 'properties' => self::CATEGORY_PROPERTIES];

    public const array SERVER_SCHEMA = ['type' => 'object', 'required' => ['identifier', 'name'], 'properties' => ['identifier' => self::STRING, 'name' => self::STRING]];

    public const array STAFF_MEMBER_SCHEMA = ['type' => 'object', 'required' => ['id', 'username'], 'properties' => ['id' => self::INTEGER, 'username' => self::STRING]];

    public const array CANNED_REPLY_SCHEMA = ['type' => 'object', 'required' => ['id', 'title', 'body'], 'properties' => ['id' => self::INTEGER, 'title' => self::STRING, 'body' => self::STRING]];

    public const array TICKET_LIST_SCHEMA = [
        'type' => 'array',
        'items' => ['type' => 'object', 'required' => self::TICKET_REQUIRED, 'properties' => self::TICKET_PROPERTIES],
    ];

    public const array THREAD_SCHEMA = [
        'type' => 'object',
        'required' => [...self::TICKET_REQUIRED, 'messages'],
        'properties' => [
            ...self::TICKET_PROPERTIES,
            'messages' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => self::MESSAGE_REQUIRED, 'properties' => self::MESSAGE_PROPERTIES]],
        ],
    ];

    public const array STAFF_TICKET_LIST_SCHEMA = [
        'type' => 'array',
        'items' => ['type' => 'object', 'required' => self::STAFF_TICKET_REQUIRED, 'properties' => self::STAFF_TICKET_PROPERTIES],
    ];

    public const array STAFF_THREAD_SCHEMA = [
        'type' => 'object',
        'required' => [...self::STAFF_TICKET_REQUIRED, 'messages'],
        'properties' => [
            ...self::STAFF_TICKET_PROPERTIES,
            'messages' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'required' => [...self::MESSAGE_REQUIRED, 'is_internal'],
                'properties' => [...self::MESSAGE_PROPERTIES, 'is_internal' => ['type' => 'boolean', 'description' => 'A staff-only internal note.']],
            ]],
        ],
    ];

    public const array PAGINATION_SCHEMA = [
        'type' => 'object',
        'required' => ['current_page', 'last_page', 'per_page', 'total'],
        'properties' => ['current_page' => self::INTEGER, 'last_page' => self::INTEGER, 'per_page' => self::INTEGER, 'total' => self::INTEGER],
    ];

    private const array INTEGER = ['type' => 'integer'];

    private const array STRING = ['type' => 'string'];

    private const array BOOLEAN = ['type' => 'boolean'];

    private const array DATE = ['type' => 'string', 'format' => 'date-time'];

    private const array NULLABLE_DATE = ['type' => 'string', 'format' => 'date-time', 'nullable' => true];

    private const array CATEGORY_PROPERTIES = ['id' => self::INTEGER, 'name' => self::STRING];

    private const array ATTACHMENT_SCHEMA = [
        'type' => 'object',
        'required' => ['id', 'name', 'mime', 'size', 'width', 'height'],
        'properties' => [
            'id' => self::INTEGER,
            'name' => self::STRING,
            'mime' => ['type' => 'string', 'enum' => ['image/png', 'image/jpeg', 'image/webp']],
            'size' => self::INTEGER,
            'width' => self::INTEGER,
            'height' => self::INTEGER,
        ],
    ];

    private const array MESSAGE_PROPERTIES = [
        'id' => self::INTEGER,
        'body' => ['type' => 'string', 'description' => 'Markdown. Render it with the safe markdown renderer, never as HTML.'],
        'author' => ['type' => 'string', 'description' => 'The username of the author.'],
        'is_staff' => self::BOOLEAN,
        'attachments' => ['type' => 'array', 'items' => self::ATTACHMENT_SCHEMA],
        'created_at' => self::DATE,
    ];

    private const array MESSAGE_REQUIRED = ['id', 'body', 'author', 'is_staff', 'attachments', 'created_at'];

    private const array TICKET_PROPERTIES = [
        'id' => self::INTEGER,
        'subject' => self::STRING,
        'status' => ['type' => 'string', 'enum' => ['open', 'answered', 'waiting_staff', 'closed']],
        'category' => [...self::CATEGORY_SCHEMA, 'nullable' => true],
        'server' => [...self::SERVER_SCHEMA, 'nullable' => true],
        'unread' => self::BOOLEAN,
        'can_reply' => ['type' => 'boolean', 'description' => 'Whether the player can still reply: the ticket is not closed, or was closed less than 7 days ago.'],
        'last_message_at' => self::NULLABLE_DATE,
        'last_message_by_staff' => self::BOOLEAN,
        'closed_at' => self::NULLABLE_DATE,
        'created_at' => self::DATE,
    ];

    private const array TICKET_REQUIRED = ['id', 'subject', 'status', 'category', 'server', 'unread', 'can_reply', 'last_message_at', 'last_message_by_staff', 'closed_at', 'created_at'];

    private const array STAFF_TICKET_PROPERTIES = [
        ...self::TICKET_PROPERTIES,
        'user' => [
            'type' => 'object',
            'required' => ['id', 'username', 'email'],
            'properties' => ['id' => self::INTEGER, 'username' => self::STRING, 'email' => self::STRING],
        ],
        'assignee' => [...self::STAFF_MEMBER_SCHEMA, 'nullable' => true],
    ];

    private const array STAFF_TICKET_REQUIRED = [...self::TICKET_REQUIRED, 'user', 'assignee'];

    /**
     * The tickets with the newest public message each of them has seen by `$viewer`, as
     * `read_message_id`, for the unread markers.
     *
     * @param  Builder<SupportTicket>  $query
     * @return Builder<SupportTicket>
     */
    public static function withReadMarker(Builder $query, User $viewer): Builder
    {
        return $query->addSelect(['read_message_id' => DB::table('support_ticket_reads')
            ->select('message_id')
            ->whereColumn('support_ticket_reads.ticket_id', 'support_tickets.id')
            ->where('support_ticket_reads.user_id', $viewer->id)
            ->limit(1),
        ]);
    }

    /**
     * The tickets waiting on `$staff`: open or waiting on staff, unassigned or assigned to them,
     * last written by the player, and not opened by them since.
     *
     * @return Builder<SupportTicket>
     */
    public static function unreadForStaff(User $staff): Builder
    {
        return SupportTicket::query()
            ->whereIn('status', ['open', 'waiting_staff'])
            ->where('last_message_by_staff', false)
            ->whereNotNull('last_message_id')
            ->where(fn (Builder $query) => $query->whereNull('assigned_to')->orWhere('assigned_to', $staff->id))
            ->whereNotExists(fn (QueryBuilder $reads) => $reads->from('support_ticket_reads')
                ->whereColumn('support_ticket_reads.ticket_id', 'support_tickets.id')
                ->where('support_ticket_reads.user_id', $staff->id)
                ->whereColumn('support_ticket_reads.message_id', '>=', 'support_tickets.last_message_id'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function ticket(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'category' => $ticket->category instanceof SupportCategory ? self::category($ticket->category) : null,
            'server' => $ticket->server === null ? null : ['identifier' => $ticket->server->uuidShort, 'name' => $ticket->server->name],
            'unread' => $ticket->last_message_by_staff && self::unseen($ticket),
            'can_reply' => $ticket->acceptsUserReply(),
            'last_message_at' => $ticket->last_message_at?->toAtomString(),
            'last_message_by_staff' => $ticket->last_message_by_staff,
            'closed_at' => $ticket->closed_at?->toAtomString(),
            'created_at' => $ticket->created_at?->toAtomString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function staffTicket(SupportTicket $ticket): array
    {
        $assignee = $ticket->assignee;

        return [
            ...self::ticket($ticket),
            'unread' => ! $ticket->last_message_by_staff && self::unseen($ticket),
            'user' => ['id' => $ticket->user->id, 'username' => $ticket->user->username, 'email' => $ticket->user->email],
            'assignee' => $assignee instanceof User ? self::staffMember($assignee) : null,
        ];
    }

    /**
     * The ticket and the messages the player sees: no internal notes.
     *
     * @return array<string, mixed>
     */
    public static function thread(SupportTicket $ticket): array
    {
        $messages = $ticket->messages()->visibleToUser()->with(['user', 'attachments'])->orderBy('id')->get();

        return [
            ...self::ticket($ticket),
            'messages' => $messages->map(fn (SupportMessage $message): array => self::message($message))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function staffThread(SupportTicket $ticket): array
    {
        $messages = $ticket->messages()->with(['user', 'attachments'])->orderBy('id')->get();

        return [
            ...self::staffTicket($ticket),
            'messages' => $messages->map(fn (SupportMessage $message): array => [...self::message($message), 'is_internal' => $message->is_internal])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function message(SupportMessage $message): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'author' => $message->user->username ?? 'Deleted user',
            'is_staff' => $message->is_staff,
            'attachments' => $message->attachments->map(fn (SupportAttachment $attachment): array => [
                'id' => $attachment->id,
                'name' => $attachment->name,
                'mime' => $attachment->mime,
                'size' => $attachment->size,
                'width' => $attachment->width,
                'height' => $attachment->height,
            ])->values()->all(),
            'created_at' => $message->created_at?->toAtomString(),
        ];
    }

    /**
     * @return array{id: int, name: string}
     */
    public static function category(SupportCategory $category): array
    {
        return ['id' => $category->id, 'name' => $category->name];
    }

    /**
     * @return array{id: int, username: string}
     */
    public static function staffMember(User $user): array
    {
        return ['id' => $user->id, 'username' => $user->username];
    }

    /**
     * @return array{id: int, title: string, body: string}
     */
    public static function cannedReply(SupportCannedReply $reply): array
    {
        return ['id' => $reply->id, 'title' => $reply->title, 'body' => $reply->body];
    }

    /**
     * @param  LengthAwarePaginator<int, SupportTicket>  $page
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    public static function pagination(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }

    /** Whether the ticket has a public message newer than the one its viewer saw last. */
    private static function unseen(SupportTicket $ticket): bool
    {
        if ($ticket->last_message_id === null) {
            return false;
        }

        $read = $ticket->getAttribute('read_message_id');

        return ! is_numeric($read) || (int) $read < $ticket->last_message_id;
    }
}
