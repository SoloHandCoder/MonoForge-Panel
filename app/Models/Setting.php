<?php

declare(strict_types=1);

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Fillable(['key', 'value'])]
#[WithoutTimestamps]
class Setting extends Model
{
    /** Every stored setting, read once per request at boot, so it is kept in the cache store. */
    public const string CACHE_KEY = 'settings:all';

    /**
     * Saving or deleting a setting through the model clears the cache at once; this only
     * bounds how long a write that bypasses the model (a migration, raw SQL) goes unseen.
     */
    public const int CACHE_SECONDS = 60;

    /**
     * Store a persistent setting, replacing any value already stored for the key.
     */
    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value ?? '']);
    }

    /**
     * Retrieve a persistent setting, or null when nothing is stored for the key.
     */
    public static function fetch(string $key): ?string
    {
        return static::query()->where('key', $key)->first()?->value;
    }

    /**
     * Every stored setting by key, from the cache store when it is there. Without a working
     * cache store this reads the table, as it did before settings were cached.
     *
     * @return array<string, string|null>
     */
    public static function values(): array
    {
        try {
            $values = Cache::get(self::CACHE_KEY);
            if (is_array($values)) {
                /** @var array<string, string|null> $values */
                return $values;
            }
        } catch (Throwable) {
            return self::readValues();
        }

        $values = self::readValues();
        rescue(fn (): bool => Cache::put(self::CACHE_KEY, $values, self::CACHE_SECONDS), report: false);

        return $values;
    }

    /**
     * A stored setting read through values(), or null when nothing is stored for the key.
     */
    public static function cached(string $key): ?string
    {
        return static::values()[$key] ?? null;
    }

    public static function forgetCache(): bool
    {
        return (bool) rescue(fn (): bool => Cache::forget(self::CACHE_KEY), false, report: false);
    }

    protected static function booted(): void
    {
        static::saved(fn (): bool => static::forgetCache());
        static::deleted(fn (): bool => static::forgetCache());
    }

    /**
     * @return array<string, string|null>
     */
    private static function readValues(): array
    {
        $values = [];
        foreach (self::query()->get(['key', 'value']) as $setting) {
            $values[$setting->key] = $setting->value;
        }

        return $values;
    }
}
