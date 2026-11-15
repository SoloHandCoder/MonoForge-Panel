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
    public const string INVALID_AGENT_KEY_MESSAGE = 'playit rejected this agent key. Use the secret_key from the config file the running agent uses (often /etc/playit/playit.toml), without quotes.';

    /**
     * @param  string|null  $reason  playit's own error code, such as "AgentNotFound" or "RequiresPlayitPremium"
     */
    public function __construct(string $message, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }

    /** The message for an administrator, with a hint on what to do when playit refused the key. */
    public function adminMessage(): string
    {
        return $this->reason === 'InvalidAgentKey' ? self::INVALID_AGENT_KEY_MESSAGE : $this->getMessage();
    }
}
