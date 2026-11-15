<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Query;

use JsonException;

/**
 * Minecraft Java Edition's Server List Ping (the request the multiplayer menu sends): a
 * handshake into the status state, a status request, and the JSON answer.
 *
 * @see https://minecraft.wiki/w/Java_Edition_protocol/Server_List_Ping
 */
final readonly class MinecraftJavaPing
{
    public const float TIMEOUT_SECONDS = 2.0;

    /** "Any version": servers answer the status request whatever protocol the client speaks. */
    private const int PROTOCOL_VERSION = -1;

    /** A status answer is a short JSON document plus an optional 64×64 icon. */
    private const int MAX_RESPONSE_BYTES = 262_144;

    private const int MAX_PLAYERS_LISTED = 100;

    public function __construct(private QueryConnector $connector) {}

    /**
     * Reads the status response packet (id 0, then the JSON as a length-prefixed string).
     */
    public static function parse(string $packet): ?QueryStatus
    {
        $packetId = self::decodeVarInt($packet, 0);
        if ($packetId === null || $packetId[0] !== 0) {
            return null;
        }

        $string = self::decodeVarInt($packet, $packetId[1]);
        if ($string === null) {
            return null;
        }

        [$jsonLength, $offset] = $string;
        if ($jsonLength < 2 || $offset + $jsonLength > mb_strlen($packet, '8bit')) {
            return null;
        }

        try {
            $status = json_decode(mb_substr($packet, $offset, $jsonLength, '8bit'), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($status) || ! is_array($status['players'] ?? null)) {
            return null;
        }

        $players = $status['players'];
        $online = $players['online'] ?? null;
        $max = $players['max'] ?? null;
        if (! is_int($online) || ! is_int($max)) {
            return null;
        }

        $version = is_array($status['version'] ?? null) && is_string($status['version']['name'] ?? null)
            ? self::clean($status['version']['name'], 64)
            : null;

        return new QueryStatus(
            max(0, $online),
            max(0, $max),
            self::clean(self::text($status['description'] ?? null), 256),
            $version,
            self::names($players['sample'] ?? null),
        );
    }

    public static function varInt(int $value): string
    {
        // Negative numbers are sent as their 32-bit two's complement, which takes five bytes.
        $value &= 0xFFFFFFFF;
        $bytes = '';
        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $bytes .= chr($value === 0 ? $byte : $byte | 0x80);
        } while ($value !== 0);

        return $bytes;
    }

    /** The server's status, or null when it does not answer like a Minecraft Java server. */
    public function ping(string $host, int $port): ?QueryStatus
    {
        $connection = $this->connector->open($host, $port, self::TIMEOUT_SECONDS);
        if (! $connection instanceof QueryConnection) {
            return null;
        }

        try {
            $handshake = "\x00".self::varInt(self::PROTOCOL_VERSION).$this->string($host).pack('n', $port).self::varInt(1);
            if (! $connection->write($this->packet($handshake).$this->packet("\x00"))) {
                return null;
            }

            $length = $this->readVarInt($connection);
            if ($length === null || $length < 2 || $length > self::MAX_RESPONSE_BYTES) {
                return null;
            }

            $packet = $connection->read($length);
            if ($packet === null) {
                return null;
            }

            return self::parse($packet);
        } finally {
            $connection->close();
        }
    }

    /**
     * The names in the player sample. Servers often fill the sample with decorative lines
     * ("§aWelcome!"), so only entries that look like player names are kept.
     *
     * @return list<string>
     */
    private static function names(mixed $sample): array
    {
        if (! is_array($sample)) {
            return [];
        }

        $names = [];
        foreach ($sample as $entry) {
            $name = is_array($entry) && is_string($entry['name'] ?? null) ? $entry['name'] : null;
            // Java names are 3-16 of [A-Za-z0-9_]; Bedrock players through Geyser get a "." prefix.
            if ($name !== null && preg_match('/^\.?\w{1,16}$/', $name) === 1 && ! in_array($name, $names, true)) {
                $names[] = $name;
            }

            if (count($names) >= self::MAX_PLAYERS_LISTED) {
                break;
            }
        }

        return $names;
    }

    /** The plain text of a chat component (a string, or {text, extra: [...]}, or a list). */
    private static function text(mixed $component, int $depth = 0): string
    {
        if (is_string($component)) {
            return $component;
        }

        if (! is_array($component) || $depth > 8) {
            return '';
        }

        if (array_is_list($component)) {
            return implode('', array_map(static fn (mixed $part): string => self::text($part, $depth + 1), $component));
        }

        $text = is_string($component['text'] ?? null) ? $component['text'] : '';
        if (is_array($component['extra'] ?? null)) {
            $text .= self::text($component['extra'], $depth + 1);
        }

        return $text;
    }

    /** Without § formatting codes and control characters, trimmed; null when nothing is left. */
    private static function clean(string $text, int $maxLength): ?string
    {
        $text = preg_replace(['/§./u', '/[\x00-\x09\x0B-\x1F\x7F]/u'], '', $text) ?? '';
        $text = mb_trim(mb_substr($text, 0, $maxLength));

        return $text === '' ? null : $text;
    }

    /**
     * @return array{int, int}|null the value and the offset after it
     */
    private static function decodeVarInt(string $bytes, int $offset): ?array
    {
        $value = 0;
        $length = mb_strlen($bytes, '8bit');
        for ($position = 0; $position < 5; $position++) {
            if ($offset >= $length) {
                return null;
            }

            $byte = ord($bytes[$offset++]);
            $value |= ($byte & 0x7F) << (7 * $position);
            if (($byte & 0x80) === 0) {
                return [$value, $offset];
            }
        }

        return null;
    }

    private function packet(string $payload): string
    {
        return self::varInt(mb_strlen($payload, '8bit')).$payload;
    }

    private function string(string $value): string
    {
        return self::varInt(mb_strlen($value, '8bit')).$value;
    }

    private function readVarInt(QueryConnection $connection): ?int
    {
        $value = 0;
        for ($position = 0; $position < 5; $position++) {
            $byte = $connection->read(1);
            if ($byte === null) {
                return null;
            }

            $value |= (ord($byte) & 0x7F) << (7 * $position);
            if ((ord($byte) & 0x80) === 0) {
                return $value;
            }
        }

        return null;
    }
}
