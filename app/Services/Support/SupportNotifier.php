<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Pterodactyl\Models\Support\SupportTicket;
use Pterodactyl\Models\User;
use Pterodactyl\Notifications\SupportReplyNotification;
use Pterodactyl\Services\Notices\NoticeService;
use Throwable;

/**
 * The in-panel notices and emails of support tickets: the player gets a notice and an email
 * when staff reply; staff get a notice for new tickets and for replies from players. Notices
 * carry a per-ticket `kind`, so a busy ticket shows one unread notice, not a stack of them.
 */
class SupportNotifier
{
    public function __construct(private readonly NoticeService $notices) {}

    public static function userPath(int $ticketId): string
    {
        return '/account/support/'.$ticketId;
    }

    public static function staffPath(int $ticketId): string
    {
        return '/panel/support/'.$ticketId;
    }

    public function ticketCreated(SupportTicket $ticket): void
    {
        $this->notifyStaff($ticket, 'New support ticket', "{$ticket->user->username} opened #{$ticket->id}: {$this->subject($ticket)}");
    }

    public function userReplied(SupportTicket $ticket): void
    {
        $this->notifyStaff($ticket, 'New reply on a support ticket', "{$ticket->user->username} replied to #{$ticket->id}: {$this->subject($ticket)}");
    }

    public function staffReplied(SupportTicket $ticket): void
    {
        $user = $ticket->user;

        $this->safely(fn (): mixed => $this->notices->notify(
            $user,
            'info',
            'Support replied to your ticket',
            "There is a new reply on #{$ticket->id}: {$this->subject($ticket)}",
            "support.reply.{$ticket->id}",
            'Open ticket',
            self::userPath($ticket->id),
        ));

        $this->safely(fn () => $user->notify(new SupportReplyNotification($ticket->id, $user->username, $ticket->subject)));
    }

    /**
     * The assigned staff member, or every administrator while the ticket is unassigned. Nobody is
     * told about their own ticket.
     */
    private function notifyStaff(SupportTicket $ticket, string $title, string $body): void
    {
        $staff = User::query()
            ->where('root_admin', true)
            ->when($ticket->assigned_to !== null, fn (Builder $query): Builder => $query->whereKey($ticket->assigned_to))
            ->whereKeyNot($ticket->user_id)
            ->limit(50)
            ->get();

        foreach ($staff as $member) {
            $this->safely(fn (): mixed => $this->notices->notify(
                $member,
                'info',
                $title,
                $body,
                "support.staff.{$ticket->id}",
                'Open ticket',
                self::staffPath($ticket->id),
            ));
        }
    }

    private function subject(SupportTicket $ticket): string
    {
        return Str::limit($ticket->subject, 120);
    }

    /**
     * A notice or mail problem must never fail the reply that triggered it.
     *
     * @param  callable(): mixed  $send
     */
    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}
