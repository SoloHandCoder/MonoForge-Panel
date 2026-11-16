<?php

declare(strict_types=1);

namespace Pterodactyl\Exceptions\Billing;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A coupon that cannot be used here. Its message is shown to the player as is (422).
 */
class CouponException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(422, $message);
    }

    /** An unknown or disabled code: the same answer, so codes cannot be probed for. */
    public static function invalid(): self
    {
        return new self('This coupon code is not valid.');
    }
}
