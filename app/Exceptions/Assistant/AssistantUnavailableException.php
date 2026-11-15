<?php

declare(strict_types=1);

namespace Pterodactyl\Exceptions\Assistant;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The assistant cannot start a turn right now: it is turned off, no provider is set up,
 * or the user has no free AI tokens and no panel tokens left. The message is shown as is.
 */
class AssistantUnavailableException extends HttpException
{
    public function __construct(string $message, int $status = 403)
    {
        parent::__construct($status, $message);
    }

    public static function noBalance(): self
    {
        return new self('You have used your free AI tokens for this month and your token balance is empty. Top up your wallet to keep using the assistant.', 402);
    }
}
