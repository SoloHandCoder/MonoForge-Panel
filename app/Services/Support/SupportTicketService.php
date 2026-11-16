<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Enum\SupportTicketStatus;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Support\SupportCategory;
use Pterodactyl\Models\Support\SupportMessage;
use Pterodactyl\Models\Support\SupportTicket;
use Pterodactyl\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Opens tickets and adds replies, moving each ticket through its statuses:
 * open → answered (staff replied) → waiting_staff (the player replied) → closed. A player's reply
 * within SupportTicket::REOPEN_DAYS of closing reopens the ticket; a staff reply always does.
 * Internal notes never change the status and never reach the player.
 */
class SupportTicketService
{
    /** @var list<string> the attachment files the running transaction wrote, removed again if it fails */
    private array $stored = [];

    public function __construct(
        private readonly SupportAttachmentStore $attachments,
        private readonly SupportNotifier $notifier,
        private readonly SupportAttachmentQuota $quota,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function open(User $user, string $subject, ?SupportCategory $category, ?Server $server, string $body, array $files): SupportTicket
    {
        $ticket = $this->withStoredFiles(function () use ($user, $subject, $category, $server, $body, $files): SupportTicket {
            $ticket = new SupportTicket([
                'subject' => $subject,
                'category_id' => $category?->id,
                'server_id' => $server?->id,
            ]);
            $ticket->user_id = $user->id;
            $ticket->status = SupportTicketStatus::Open;
            $ticket->save();

            $message = $this->addMessage($ticket, $user, $body, false, false, $files);
            $this->recordPublicMessage($ticket, $message);
            $ticket->save();
            $this->markRead($ticket, $user);

            return $ticket;
        });

        $this->notifier->ticketCreated($ticket);

        return $ticket;
    }

    /**
     * A reply from the ticket's player.
     *
     * @param  list<UploadedFile>  $files
     *
     * @throws ConflictHttpException when the ticket was closed more than REOPEN_DAYS ago
     */
    public function replyAsUser(SupportTicket $ticket, User $user, string $body, array $files): SupportMessage
    {
        $message = $this->withStoredFiles(function () use ($ticket, $user, $body, $files): SupportMessage {
            $locked = $this->lock($ticket);
            throw_unless($locked->acceptsUserReply(), ConflictHttpException::class, 'This ticket was closed more than '.SupportTicket::REOPEN_DAYS.' days ago. Open a new ticket instead.');

            $message = $this->addMessage($locked, $user, $body, false, false, $files);
            // A ticket staff have not answered yet stays open; any answered or closed one now waits on staff.
            if ($locked->status !== SupportTicketStatus::Open) {
                $locked->status = SupportTicketStatus::WaitingStaff;
            }

            $locked->closed_at = null;
            $this->recordPublicMessage($locked, $message);
            $locked->save();
            $this->markRead($locked, $user);

            return $message;
        });

        $this->notifier->userReplied($ticket->refresh());

        return $message;
    }

    /**
     * A staff reply, or with `$internal` a note only staff see.
     *
     * @param  list<UploadedFile>  $files
     */
    public function replyAsStaff(SupportTicket $ticket, User $staff, string $body, bool $internal, array $files): SupportMessage
    {
        $message = $this->withStoredFiles(function () use ($ticket, $staff, $body, $internal, $files): SupportMessage {
            $locked = $this->lock($ticket);
            $message = $this->addMessage($locked, $staff, $body, true, $internal, $files);

            if (! $internal) {
                $locked->status = SupportTicketStatus::Answered;
                $locked->closed_at = null;
                $this->recordPublicMessage($locked, $message);
                $locked->save();
                $this->markRead($locked, $staff);
            }

            return $message;
        });

        if (! $internal) {
            $this->notifier->staffReplied($ticket->refresh());
        }

        return $message;
    }

    public function close(SupportTicket $ticket): SupportTicket
    {
        return $this->setStatus($ticket, SupportTicketStatus::Closed);
    }

    /** Staff set any status; closing stamps the time the reopen window counts from. */
    public function setStatus(SupportTicket $ticket, SupportTicketStatus $status): SupportTicket
    {
        if ($ticket->status === $status) {
            return $ticket;
        }

        $ticket->status = $status;
        $ticket->closed_at = $status === SupportTicketStatus::Closed ? CarbonImmutable::now() : null;
        $ticket->save();

        return $ticket;
    }

    /**
     * Remembers the newest public message the person has seen on the ticket, and reads their panel
     * notice about it: opening or answering the ticket covers it, as answering a friend request does.
     */
    public function markRead(SupportTicket $ticket, User $user): void
    {
        $user->notices()->unread()
            ->whereIn('kind', [SupportNotifier::userKind($ticket->id), SupportNotifier::staffKind($ticket->id)])
            ->update(['read_at' => CarbonImmutable::now()]);

        if ($ticket->last_message_id === null) {
            return;
        }

        DB::table('support_ticket_reads')->upsert(
            [['ticket_id' => $ticket->id, 'user_id' => $user->id, 'message_id' => $ticket->last_message_id]],
            ['ticket_id', 'user_id'],
            ['message_id'],
        );
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function addMessage(SupportTicket $ticket, User $author, string $body, bool $staff, bool $internal, array $files): SupportMessage
    {
        $message = new SupportMessage(['user_id' => $author->id, 'is_staff' => $staff, 'is_internal' => $internal, 'body' => $body]);
        $message->ticket_id = $ticket->id;
        $message->save();

        $files = array_slice($files, 0, SupportAttachmentStore::MAX_PER_MESSAGE);
        // The cheap count first (it also locks the author's row), then decode and re-encode, then
        // the size limit on what will really be stored.
        $this->quota->assertDailyCount($author, count($files), $staff);
        $prepared = array_map($this->attachments->prepare(...), $files);
        $this->quota->assertStorage($author, array_sum(array_map(fn (array $image): int => mb_strlen($image['bytes'], '8bit'), $prepared)));

        foreach ($prepared as $image) {
            $this->stored[] = $this->attachments->put($message, $image)->path;
        }

        return $message;
    }

    private function recordPublicMessage(SupportTicket $ticket, SupportMessage $message): void
    {
        $ticket->last_message_id = $message->id;
        $ticket->last_message_at = CarbonImmutable::now();
        $ticket->last_message_by_staff = $message->is_staff;
    }

    private function lock(SupportTicket $ticket): SupportTicket
    {
        return SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
    }

    /**
     * Runs the work in a transaction; attachment files it stored are deleted again when it fails.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function withStoredFiles(callable $work): mixed
    {
        $this->stored = [];

        try {
            return DB::transaction(fn (): mixed => $work());
        } catch (Throwable $throwable) {
            $this->attachments->delete($this->stored);

            throw $throwable;
        } finally {
            $this->stored = [];
        }
    }
}
