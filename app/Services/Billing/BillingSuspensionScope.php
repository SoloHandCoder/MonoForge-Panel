<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

/**
 * Marks the suspension changes billing itself makes. Any other change to a server's suspension
 * (an administrator in the admin area, the application API) clears the subscription's
 * `suspended_by_billing`, so billing never lifts a suspension it did not make.
 *
 * The marker is process-wide static state, reset in `finally`. That is right for PHP-FPM and
 * queue workers, which run one request or job at a time; under fibers or a concurrent runtime
 * (Octane with Swoole), another request's suspension during billing's Wings call would be
 * taken for billing's.
 */
final class BillingSuspensionScope
{
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
