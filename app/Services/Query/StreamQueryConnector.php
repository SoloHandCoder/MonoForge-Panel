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
        $stream = @stream_socket_client("tcp://{$address}:{$port}", $errorCode, $errorMessage, $timeoutSeconds);
        if ($stream === false) {
            return null;
        }

        return new class($stream, $deadline) implements QueryConnection
        {
            /**
             * @param  resource  $stream
             */
            public function __construct(private $stream, private readonly float $deadline) {}

            public function write(string $bytes): bool
            {
                if (! $this->timeLeft()) {
                    return false;
                }

                $written = 0;
                $length = mb_strlen($bytes, '8bit');
                while ($written < $length) {
                    $chunk = @fwrite($this->stream, mb_substr($bytes, $written, null, '8bit'));
                    if ($chunk === false || $chunk === 0) {
                        return false;
                    }

                    $written += $chunk;
                }

                return true;
            }

            public function read(int $length): ?string
            {
                $buffer = '';
                while (mb_strlen($buffer, '8bit') < $length) {
                    if (! $this->timeLeft()) {
                        return null;
                    }

                    $chunk = @fread($this->stream, max(1, $length - mb_strlen($buffer, '8bit')));
                    if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                        return null;
                    }

                    $buffer .= $chunk;
                }

                return $buffer;
            }

            public function close(): void
            {
                @fclose($this->stream);
            }

            /** Sets the socket timeout to what is left of the deadline; false once it passed. */
            private function timeLeft(): bool
            {
                $left = $this->deadline - microtime(true);
                if ($left <= 0) {
                    return false;
                }

                $seconds = (int) floor($left);
                stream_set_timeout($this->stream, $seconds, (int) (($left - $seconds) * 1_000_000));

                return true;
            }
        };
    }
}
