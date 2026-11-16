<?php

declare(strict_types=1);

namespace Pterodactyl\Enum;

/**
 * Where "Set up automatic tunnels" stands for a node: waiting for the administrator to open and
 * accept the claim on playit.gg, accepted and being exchanged for the agent secret, refused or
 * expired, ready for the node's switch script, or done (the node runs the new agent).
 */
enum PlayitClaimStatus: string
{
    case WaitingForVisit = 'waiting_for_visit';
    case WaitingForUser = 'waiting_for_user';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Ready = 'ready';
    case Switched = 'switched';

    /** Whether the claim still waits on playit.gg (the panel polls setup and exchange). */
    public function isWaiting(): bool
    {
        return in_array($this, [self::WaitingForVisit, self::WaitingForUser, self::Accepted], true);
    }
}
