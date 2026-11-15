<?php

declare(strict_types=1);

namespace Pterodactyl\Enum;

/**
 * Where a support ticket stands: `open` until staff answer, `answered` after a staff reply,
 * `waiting_staff` after the player replies to an answer, and `closed` by staff or the player.
 */
enum SupportTicketStatus: string
{
    case Open = 'open';
    case Answered = 'answered';
    case WaitingStaff = 'waiting_staff';
    case Closed = 'closed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }

    /** Whether the ticket waits on staff, which the staff badge counts. */
    public function awaitsStaff(): bool
    {
        return $this === self::Open || $this === self::WaitingStaff;
    }
}
