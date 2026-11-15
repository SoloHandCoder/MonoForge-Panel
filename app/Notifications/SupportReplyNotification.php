<?php

declare(strict_types=1);

namespace Pterodactyl\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Pterodactyl\Services\Support\SupportNotifier;

/**
 * Tells a player that staff replied to their support ticket. The reply itself stays in the
 * panel: the email names the ticket and links to it.
 */
class SupportReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $ticketId,
        public readonly string $username,
        public readonly string $ticketSubject,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(): array
    {
        return ['mail'];
    }

    public function toMail(): MailMessage
    {
        return (new MailMessage)
            ->subject("New reply to your support ticket #{$this->ticketId}")
            ->greeting('Hello '.$this->username.'!')
            ->line("Our team replied to your support ticket \"{$this->ticketSubject}\".")
            ->line('Open the ticket in the panel to read the reply and answer it.')
            ->action('Open ticket', url(SupportNotifier::userPath($this->ticketId)));
    }
}
