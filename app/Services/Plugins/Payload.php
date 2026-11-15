<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Plugins;

/**
 * Reads values out of decoded catalog JSON without trusting its shape: a missing or
 * mistyped value comes back as the fallback.
 */
final class Payload
{
    public static function string(mixed $data, string $key, string $default = ''): string
    {
        $value = is_array($data) ? ($data[$key] ?? null) : null;

        return is_string($value) ? $value : (is_int($value) ? (string) $value : $default);
    }

    public static function nullableString(mixed $data, string $key): ?string
    {
        $value = self::string($data, $key);

        return $value === '' ? null : $value;
    }

    public static function int(mixed $data, string $key): int
    {
        $value = is_array($data) ? ($data[$key] ?? null) : null;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    public static function bool(mixed $data, string $key): bool
    {
        $value = is_array($data) ? ($data[$key] ?? null) : null;

        return $value === true;
    }

    /** @return array<mixed> */
    public static function array(mixed $data, string $key): array
    {
        $value = is_array($data) ? ($data[$key] ?? null) : null;

        return is_array($value) ? $value : [];
    }

    /** @return list<array<mixed>> the entries of a list that are objects */
    public static function objects(mixed $data, ?string $key = null): array
    {
        $list = $key === null ? (is_array($data) ? $data : []) : self::array($data, $key);

        return array_values(array_filter($list, is_array(...)));
    }

    /** @return list<string> */
    public static function strings(mixed $data, string $key): array
    {
        return array_values(array_filter(self::array($data, $key), is_string(...)));
    }
}
