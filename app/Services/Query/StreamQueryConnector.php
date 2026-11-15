<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Query;

/**
 * Plain PHP TCP sockets. The timeout covers the whole conversation, not each read, so a
 * server that trickles bytes still gives up in time.
 */
final class StreamQueryConnector implements QueryConnector
{
    public function open(string $host, int $port, float $timeoutSeconds): ?QueryConnection
    {
        $address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? $host : "[{$host}]";
        $deadline = microtime(true) + $timeoutSeconds;

        $errorCode = 0;
        $errorMessage = '';
        // An offline server is an expected answer, not a warning for the logs.
        set_error_handler(static fn (): bool => true);
        try {
            $stream = stream_socket_client("tcp://{$address}:{$port}", $errorCode, $errorMessage, $timeoutSeconds);
        } finally {
            restore_error_handler();
        }

        return $stream === false ? null : new StreamQueryConnection($stream, $deadline);
    }
}
