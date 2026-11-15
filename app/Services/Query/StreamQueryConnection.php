<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Query;

/**
 * A query connection over a PHP stream socket, with one deadline for the whole conversation.
 */
final class StreamQueryConnection implements QueryConnection
{
    /**
     * @param  mixed  $stream  the socket resource from stream_socket_client()
     */
    public function __construct(private mixed $stream, private readonly float $deadline) {}

    public function write(string $bytes): bool
    {
        $stream = $this->stream();
        if ($stream === null) {
            return false;
        }

        $written = 0;
        $length = mb_strlen($bytes, '8bit');
        while ($written < $length) {
            $chunk = @fwrite($stream, mb_substr($bytes, $written, null, '8bit'));
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
            $stream = $this->stream();
            if ($stream === null) {
                return null;
            }

            $chunk = @fread($stream, max(1, $length - mb_strlen($buffer, '8bit')));
            if ($chunk === false || ($chunk === '' && feof($stream))) {
                return null;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }

        $this->stream = null;
    }

    /**
     * The open socket with its timeout set to what is left of the deadline; null once the
     * deadline passed or the connection is closed.
     *
     * @return resource|null
     */
    private function stream(): mixed
    {
        $left = $this->deadline - microtime(true);
        if (! is_resource($this->stream) || $left <= 0) {
            return null;
        }

        $seconds = (int) floor($left);
        stream_set_timeout($this->stream, $seconds, (int) (($left - $seconds) * 1_000_000));

        return $this->stream;
    }
}
