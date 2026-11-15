<?php

declare(strict_types=1);

namespace Pterodactyl\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A billing email about one shop server: its balance is short before a renewal, it was
 * suspended for an unpaid renewal, it is deleted tomorrow, or it was deleted.
 */
class SubscriptionBillingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $event,
        public readonly string $username,
        public readonly string $subject,
        public readonly array $lines,
        public readonly string $actionLabel,
        public readonly string $actionPath,
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
        $message = (new MailMessage)
            ->subject($this->subject)
            ->greeting('Hello '.$this->username.'!');

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        return $message->action($this->actionLabel, url($this->actionPath));
    }
}
