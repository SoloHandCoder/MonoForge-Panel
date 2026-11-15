<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant;

use Illuminate\Support\Arr;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Setting;

/**
 * The AI assistant's settings: stored as "settings::monoforge:ai:*" rows and loaded into
 * config("monoforge.ai.*") by the SettingsServiceProvider, with the defaults in
 * config/monoforge.php.
 */
final class AssistantSettings
{
    public const int MAX_RATIO = 1_000_000;

    public const int MAX_FREE_TOKENS = 100_000_000;

    public const int MAX_TOOL_ITERATIONS = 25;

    public const int MIN_CONTEXT_TOKENS = 2000;

    public const int MAX_CONTEXT_TOKENS = 1_000_000;

    public const int MAX_OUTPUT_TOKENS = 32768;

    public static function enabled(): bool
    {
        return config()->boolean('monoforge.ai.enabled');
    }

    public static function serverEnabled(): bool
    {
        return config()->boolean('monoforge.ai.server_enabled', true);
    }

    public static function adminEnabled(): bool
    {
        return config()->boolean('monoforge.ai.admin_enabled', true);
    }

    /** Whether the crash doctor switch is on; see crashDoctorAvailable() for whether it can be used. */
    public static function crashDoctorEnabled(): bool
    {
        return config()->boolean('monoforge.ai.crash_doctor_enabled', true);
    }

    /** The crash doctor runs on the server assistant, so it needs that on as well as its own switch. */
    public static function crashDoctorAvailable(): bool
    {
        return self::crashDoctorEnabled() && self::scopeEnabled(AiConversation::SCOPE_SERVER);
    }

    /** Whether the assistant of this scope may be used at all. */
    public static function scopeEnabled(string $scope): bool
    {
        return self::enabled() && ($scope === AiConversation::SCOPE_ADMIN ? self::adminEnabled() : self::serverEnabled());
    }

    public static function defaultProviderId(): ?int
    {
        $value = self::value('default_provider_id');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** AI tokens per panel token. */
    public static function ratio(): int
    {
        return max(1, min(self::MAX_RATIO, self::integer('ratio', 1000)));
    }

    public static function freeTokensMonthly(): int
    {
        return max(0, min(self::MAX_FREE_TOKENS, self::integer('free_tokens_monthly', 50000)));
    }

    public static function warnPercent(): int
    {
        return max(1, min(100, self::integer('warn_percent', 80)));
    }

    public static function maxToolIterations(): int
    {
        return max(1, min(self::MAX_TOOL_ITERATIONS, self::integer('max_tool_iterations', 8)));
    }

    public static function contextTokens(): int
    {
        return max(self::MIN_CONTEXT_TOKENS, min(self::MAX_CONTEXT_TOKENS, self::integer('context_tokens', 24000)));
    }

    public static function maxOutputTokens(): int
    {
        return max(64, min(self::MAX_OUTPUT_TOKENS, self::integer('max_output_tokens', 2048)));
    }

    /**
     * @return array{enabled: bool, server_enabled: bool, admin_enabled: bool, crash_doctor_enabled: bool, default_provider_id: int|null, ratio: int, free_tokens_monthly: int, warn_percent: int, max_tool_iterations: int, context_tokens: int, max_output_tokens: int}
     */
    public static function all(): array
    {
        return [
            'enabled' => self::enabled(),
            'server_enabled' => self::serverEnabled(),
            'admin_enabled' => self::adminEnabled(),
            'crash_doctor_enabled' => self::crashDoctorEnabled(),
            'default_provider_id' => self::defaultProviderId(),
            'ratio' => self::ratio(),
            'free_tokens_monthly' => self::freeTokensMonthly(),
            'warn_percent' => self::warnPercent(),
            'max_tool_iterations' => self::maxToolIterations(),
            'context_tokens' => self::contextTokens(),
            'max_output_tokens' => self::maxOutputTokens(),
        ];
    }

    /**
     * Stores the given settings and applies them to this request's configuration.
     *
     * @param  array<string, bool|int|null>  $values
     */
    public static function store(array $values): void
    {
        foreach ($values as $key => $value) {
            $stored = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => 'null',
                default => (string) $value,
            };

            Setting::put('settings::monoforge:ai:'.$key, $stored);
            config()->set('monoforge.ai.'.$key, $value);
        }
    }

    private static function integer(string $key, int $default): int
    {
        $value = self::value($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /** A stored value as it is: settings rows are strings until the provider casts them. */
    private static function value(string $key): mixed
    {
        return Arr::get(config()->array('monoforge.ai'), $key);
    }
}
