<?php

declare(strict_types=1);

namespace Pterodactyl\Exceptions\Plugins;

use Pterodactyl\Enum\MarketplaceSource;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A one-click install or catalog lookup failed in a way the user should see: a catalog that
 * did not answer, a file that is not allowed or failed its hash check. The message is shown
 * as is.
 */
class MarketplaceException extends HttpException
{
    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($status, $message);
    }

    public static function unavailable(MarketplaceSource $source): self
    {
        return new self("{$source->label()} did not answer. Try again in a minute.", 502);
    }

    public static function busy(MarketplaceSource $source): self
    {
        return new self("Too many {$source->label()} lookups right now. Try again in a minute.", 429);
    }
}
