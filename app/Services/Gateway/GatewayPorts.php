<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Gateway;

/**
 * The port list of the game gateway, with the same syntax and limits as
 * `scripts/monoforge/gateway.sh --ports`: one to seven comma-separated ports or ranges such as
 * `25565-25700,7777`, each between 1024 and 65535, never including SSH (22) or the WireGuard
 * port (51820).
 *
 * @phpstan-type PortRange array{int, int}
 */
final class GatewayPorts
{
    /** What the script forwards when it is given no `--ports`. */
    public const string DEFAULT = '25565-25700';

    public const int MAX_RANGES = 7;

    public const int MIN_PORT = 1024;

    public const int MAX_PORT = 65535;

    /** The UDP port WireGuard listens on, on the VPS. */
    public const int WIREGUARD_PORT = 51820;

    public const int SSH_PORT = 22;

    /**
     * Why the list is not accepted, or null when it is.
     */
    public static function error(string $list): ?string
    {
        $parts = self::parts($list);
        if ($parts === [] || count($parts) > self::MAX_RANGES) {
            return sprintf('Enter 1 to %d ports or ranges separated by commas, such as %s.', self::MAX_RANGES, self::DEFAULT);
        }

        foreach ($parts as $part) {
            if (preg_match('/^(\d{1,5})(?:-(\d{1,5}))?$/D', $part, $matches) !== 1) {
                return sprintf('"%s" is not a port or a range such as 25565-25700.', $part);
            }

            $from = (int) $matches[1];
            $to = isset($matches[2]) ? (int) $matches[2] : $from;

            if ($from <= self::SSH_PORT && $to >= self::SSH_PORT) {
                return sprintf('The range %s includes the SSH port %d, which is never forwarded.', $part, self::SSH_PORT);
            }

            if ($from < self::MIN_PORT || $to > self::MAX_PORT || $from > $to) {
                return sprintf('Ports must be between %d and %d, the lower one first: %s.', self::MIN_PORT, self::MAX_PORT, $part);
            }

            if ($from <= self::WIREGUARD_PORT && $to >= self::WIREGUARD_PORT) {
                return sprintf('The range %s includes the WireGuard port %d.', $part, self::WIREGUARD_PORT);
            }
        }

        return null;
    }

    /**
     * The ranges of a valid list, as [from, to] pairs; an invalid list has none.
     *
     * @return list<PortRange>
     */
    public static function parse(?string $list): array
    {
        if ($list === null || self::error($list) !== null) {
            return [];
        }

        $ranges = [];
        foreach (self::parts($list) as $part) {
            [$from, $to] = array_pad(explode('-', $part, 2), 2, null);
            $ranges[] = [(int) $from, (int) ($to ?? $from)];
        }

        return $ranges;
    }

    /** The list as the script takes it: no spaces, single ports without a dash. */
    public static function normalize(string $list): string
    {
        return implode(',', array_map(
            fn (array $range): string => $range[0] === $range[1] ? (string) $range[0] : $range[0].'-'.$range[1],
            self::parse($list),
        ));
    }

    /**
     * @param  list<PortRange>  $ranges
     */
    public static function contains(array $ranges, int $port): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($port >= $from && $port <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function parts(string $list): array
    {
        $trimmed = mb_trim($list);
        if ($trimmed === '') {
            return [];
        }

        return array_map(mb_trim(...), explode(',', $trimmed));
    }
}
