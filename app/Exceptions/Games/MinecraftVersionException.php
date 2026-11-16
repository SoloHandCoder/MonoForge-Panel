<?php

declare(strict_types=1);

namespace Pterodactyl\Exceptions\Games;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A Versions page lookup or install failed in a way the user should see: a version list that
 * did not answer, a version that does not exist, an egg that cannot run the installer. The
 * message is shown as is.
 */
class MinecraftVersionException extends HttpException
{
    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($status, $message);
    }

    public static function unavailable(string $source): self
    {
        return new self("{$source} did not answer. Try again in a minute.", 502);
    }
}
