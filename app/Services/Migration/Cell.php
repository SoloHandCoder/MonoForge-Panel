<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Migration;

use UnexpectedValueException;

/**
 * Narrows values read from raw database rows, which arrive untyped, to the
 * scalar types the importer works with.
 */
final class Cell
{
    public static function string(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        throw new UnexpectedValueException('Expected a scalar database value, got '.get_debug_type($value).'.');
    }

    public static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : self::string($value);
    }

    public static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        if (is_bool($value) || is_float($value)) {
            return (int) $value;
        }

        throw new UnexpectedValueException('Expected an integer database value, got '.get_debug_type($value).'.');
    }

    /**
     * Read one column from a raw row object or array, with lower-cased keys so
     * MySQL and MariaDB information_schema results read the same.
     */
    public static function column(mixed $row, string $name): mixed
    {
        return array_change_key_case(self::row($row))[$name] ?? null;
    }

    /**
     * A raw row from the query builder (stdClass or array) as a column => value map.
     *
     * @return array<string, mixed>
     */
    public static function row(mixed $row): array
    {
        if (! is_object($row) && ! is_array($row)) {
            throw new UnexpectedValueException('Expected a database row, got '.get_debug_type($row).'.');
        }

        $out = [];
        foreach ((array) $row as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
