<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Settings;

use Illuminate\Support\Arr;

/**
 * Which MonoForge settings come from the environment. With APP_ENVIRONMENT_ONLY=true a
 * monoforge:* setting whose environment variable is set is "locked": the variable wins over
 * the database and the panel cannot change it. A setting whose variable is unset (or that
 * has none) is loaded from the database and stays editable. Outside environment-only mode
 * nothing is locked and stored values win, as before.
 */
final class EnvironmentSettings
{
    public static function environmentOnly(): bool
    {
        return config()->boolean('pterodactyl.load_environment_only');
    }

    /**
     * The setting keys whose environment variable is set, worked out when the configuration
     * loaded.
     *
     * @return list<string>
     */
    public static function setInEnvironment(): array
    {
        $keys = Arr::get(config()->all(), 'monoforge.settings.set_in_env');

        return is_array($keys) ? array_values(array_filter($keys, is_string(...))) : [];
    }

    /** Whether the panel must leave this setting alone because the environment sets it. */
    public static function locked(string $key): bool
    {
        return self::environmentOnly() && in_array($key, self::setInEnvironment(), true);
    }
}
