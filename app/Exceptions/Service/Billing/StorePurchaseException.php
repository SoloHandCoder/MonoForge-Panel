<?php

declare(strict_types=1);

namespace Pterodactyl\Exceptions\Service\Billing;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * A store purchase that was refused or failed. Its message is shown to the player as is, so
 * it never carries internal details such as node addresses.
 */
class StorePurchaseException extends HttpException
{
    /** The checkout text the owner asked for when the shop has sold out its capacity. */
    public const string CAPACITY_MESSAGE = "The server currently can't provide servers, we hit our limit!";

    public static function disabled(): self
    {
        return new self(403, 'The store is closed right now.');
    }

    public static function unavailable(string $message): self
    {
        return new self(422, $message);
    }

    public static function capacity(): self
    {
        return new self(409, self::CAPACITY_MESSAGE);
    }

    /** Another purchase holds the capacity lock for longer than the wait. */
    public static function busy(): self
    {
        return new self(409, 'The store is busy with other orders. Nothing was charged; please try again in a moment.');
    }

    public static function noNode(): self
    {
        return new self(409, 'There is no room for this server on the chosen location right now. Try another location or a smaller plan.');
    }

    public static function priceChanged(int $total): self
    {
        return new self(409, sprintf('The price changed to %s tokens. Please check your order and confirm again.', number_format($total)));
    }

    public static function insufficient(int $needed, int $balance, ?Throwable $previous = null): self
    {
        return new self(402, sprintf('This server costs %s tokens but your balance is %s tokens. Top up your wallet and try again.', number_format($needed), number_format($balance)), $previous);
    }

    public static function inProgress(): self
    {
        return new self(409, 'This purchase is already being processed. Please wait a moment.');
    }

    public static function alreadyFailed(): self
    {
        return new self(409, 'This checkout already failed and was refunded. Please start the purchase again.');
    }

    public static function refunded(int $tokens, string $reason, Throwable $previous): self
    {
        return new self(502, sprintf("We couldn't create your server, so your %s tokens were refunded. %s", number_format($tokens), $reason), $previous);
    }
}
