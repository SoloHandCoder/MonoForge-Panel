<?php

declare(strict_types=1);

namespace Pterodactyl\Services\ServerPages;

use Illuminate\Cache\RateLimiter;
use Pterodactyl\Contracts\Servers\WakesServers;
use Pterodactyl\Models\Server;

/**
 * The public "Wake server" button. A server is woken at most once a minute, and one visitor (by
 * the keyed hash of their address) wakes at most five servers an hour, whatever they click.
 */
final readonly class ServerPageWake
{
    public const int SERVER_DECAY_SECONDS = 60;

    public const int VISITOR_ATTEMPTS = 5;

    public const int VISITOR_DECAY_SECONDS = 3600;

    public function __construct(private RateLimiter $limiter, private WakesServers $sleep) {}

    /**
     * @return array{woken: bool, sleeping: bool, retry_after: int|null}
     */
    public function wake(Server $server, ServerPageVisitor $visitor): array
    {
        if (! $this->sleep->isSleeping($server)) {
            return ['woken' => false, 'sleeping' => false, 'retry_after' => null];
        }

        $serverKey = 'server-page-wake:server:'.$server->id;
        $visitorKey = 'server-page-wake:visitor:'.$visitor->addressKey('wake', 'all');

        $retry = 0;
        if ($this->limiter->tooManyAttempts($serverKey, 1)) {
            $retry = $this->limiter->availableIn($serverKey);
        }

        if ($this->limiter->tooManyAttempts($visitorKey, self::VISITOR_ATTEMPTS)) {
            $retry = max($retry, $this->limiter->availableIn($visitorKey));
        }

        if ($retry > 0) {
            return ['woken' => false, 'sleeping' => true, 'retry_after' => $retry];
        }

        $this->limiter->hit($serverKey, self::SERVER_DECAY_SECONDS);
        $this->limiter->hit($visitorKey, self::VISITOR_DECAY_SECONDS);

        $woken = $this->sleep->wake($server, public: true);

        return ['woken' => $woken, 'sleeping' => ! $woken, 'retry_after' => null];
    }
}
