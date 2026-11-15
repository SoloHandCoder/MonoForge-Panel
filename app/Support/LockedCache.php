<?php

declare(strict_types=1);

namespace Pterodactyl\Support;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;

/**
 * A cache `remember` that only one request computes when the entry is missing: the others wait on a
 * lock and then read what that request stored, so an expired public payload is never rebuilt by
 * every concurrent visitor at once.
 */
final class LockedCache
{
    /** How long a request waits for another's build before building the value itself. */
    private const int WAIT_SECONDS = 5;

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $build
     * @return TValue
     */
    public static function remember(Repository $cache, string $key, int $seconds, Closure $build): mixed
    {
        if ($cache->has($key)) {
            return $cache->remember($key, $seconds, $build);
        }

        $store = $cache->getStore();
        if (! $store instanceof LockProvider) {
            return $cache->remember($key, $seconds, $build);
        }

        try {
            return $store->lock($key.':build', self::WAIT_SECONDS * 2)
                ->block(self::WAIT_SECONDS, fn (): mixed => $cache->remember($key, $seconds, $build));
        } catch (LockTimeoutException) {
            return $cache->remember($key, $seconds, $build);
        }
    }
}
