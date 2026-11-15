<?php

declare(strict_types=1);

namespace Pterodactyl\Exceptions\Tunnels;

use RuntimeException;

/**
 * A playit.gg API call that failed. The message is safe to store and show to administrators:
 * it never contains the agent secret.
 */
class PlayitException extends RuntimeException
{
    /**
     * @param  string|null  $reason  playit's own error code, such as "AgentNotFound" or "RequiresPlayitPremium"
     */
    public function __construct(string $message, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }
}
