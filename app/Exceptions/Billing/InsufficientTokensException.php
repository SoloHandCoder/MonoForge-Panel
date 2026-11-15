<?php

declare(strict_types=1);

namespace Pterodactyl\Exceptions\Billing;

use Pterodactyl\Exceptions\DisplayException;

/**
 * A debit would take a user's token balance below zero. Nothing was changed.
 */
class InsufficientTokensException extends DisplayException
{
    public function __construct(public readonly int $balance = 0, public readonly int $required = 0)
    {
        parent::__construct($required > 0
            ? sprintf('This needs %d tokens, but your balance is %d tokens.', $required, $balance)
            : 'Your token balance is too low.');
    }
}
