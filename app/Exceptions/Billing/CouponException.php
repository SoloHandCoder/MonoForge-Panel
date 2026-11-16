<?php

declare(strict_types=1);

namespace Pterodactyl\Exceptions\Billing;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A coupon that cannot be used here. Its message is shown to the player as is (422).
 */
class CouponException extends HttpException
{
    /**
     * The one answer for a code that is unknown, disabled, not valid yet, expired, used up or
     * not for this player, so codes cannot be probed for.
     */
    public const string GENERIC = "This code can't be used.";

    public function __construct(string $message)
    {
        parent::__construct(422, $message);
    }

    public static function invalid(): self
    {
        return new self(self::GENERIC);
    }
}
