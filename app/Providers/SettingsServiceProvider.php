<?php

declare(strict_types=1);

namespace Pterodactyl\Providers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface as Log;
use Pterodactyl\Models\Setting;
use Pterodactyl\Services\Settings\EnvironmentSettings;

class SettingsServiceProvider extends ServiceProvider
{
    // --- Phase 2 Task H (Resend email) ---
    /**
     * Mail drivers the admin Email settings can select through `mail:default`.
     *
     * @var list<string>
     */
    public const array MAIL_DRIVERS = ['smtp', 'resend', 'log'];

    /**
     * An array of configuration keys to override with database values
     * if they exist.
     *
     * @var list<string>
     */
    protected array $keys = [
        'app:name',
        'app:locale',
        'recaptcha:enabled',
        'recaptcha:secret_key',
        'recaptcha:website_key',
        'pterodactyl:guzzle:timeout',
        'pterodactyl:guzzle:connect_timeout',
        'pterodactyl:console:count',
        'pterodactyl:console:frequency',
        'pterodactyl:auth:2fa_required',
        'pterodactyl:client_features:allocations:enabled',
        'pterodactyl:client_features:allocations:range_start',
        'pterodactyl:client_features:allocations:range_end',
        'monoforge:update:mode',
        // Phase 2 Task G: Discord and Google login providers.
        'monoforge:auth:discord:enabled',
        'monoforge:auth:discord:client_id',
        'monoforge:auth:discord:client_secret',
        'monoforge:auth:google:enabled',
        'monoforge:auth:google:client_id',
        'monoforge:auth:google:client_secret',
        // Phase 2 Task F: sign-up and captcha.
        'monoforge:auth:registration:enabled',
        'monoforge:captcha:provider',
        'monoforge:captcha:turnstile:site_key',
        'monoforge:captcha:turnstile:secret_key',
        // Phase 3 Task R: token shop switch, grace period and capacity limits.
        'monoforge:shop:enabled',
        'monoforge:shop:grace_days',
        'monoforge:shop:low_balance_days',
        'monoforge:shop:limits:memory_mb',
        'monoforge:shop:limits:disk_mb',
        'monoforge:shop:limits:cpu_percent',
        // Phase 4 Task W: the AI assistant.
        'monoforge:ai:enabled',
        'monoforge:ai:server_enabled',
        'monoforge:ai:admin_enabled',
        'monoforge:ai:default_provider_id',
        'monoforge:ai:ratio',
        'monoforge:ai:free_tokens_monthly',
        'monoforge:ai:warn_percent',
        'monoforge:ai:max_tool_iterations',
        'monoforge:ai:context_tokens',
        'monoforge:ai:max_output_tokens',
        // Addresses hidden from activity entries.
        'monoforge:privacy:hidden_ips',
    ];

    /**
     * Keys specific to the mail driver that are only grabbed from the database
     * when using the SMTP driver.
     *
     * @var list<string>
     */
    protected array $emailKeys = [
        'mail:mailers:smtp:host',
        'mail:mailers:smtp:port',
        'mail:mailers:smtp:encryption',
        'mail:mailers:smtp:username',
        'mail:mailers:smtp:password',
        'mail:from:address',
        'mail:from:name',
    ];

    /**
     * Keys only grabbed from the database when Resend is the mail driver.
     *
     * @var list<string>
     */
    protected array $resendKeys = [
        'services:resend:key',
        'mail:from:address',
        'mail:from:name',
    ];

    // --- end Task H ---

    /**
     * Keys that are encrypted and should be decrypted when set in the
     * configuration array.
     *
     * @var list<string>
     */
    protected static array $encrypted = [
        'mail:mailers:smtp:password',
        // Phase 2 Task H (Resend email).
        'services:resend:key',
        // Phase 2 Task G: Discord and Google login providers.
        'monoforge:auth:discord:client_secret',
        'monoforge:auth:google:client_secret',
        // Phase 2 Task F: sign-up and captcha.
        'monoforge:captcha:turnstile:secret_key',
    ];

    /**
     * @return list<string>
     */
    public static function getEncryptedKeys(): array
    {
        return self::$encrypted;
    }

    /**
     * Boot the service provider. With `APP_ENVIRONMENT_ONLY` (pterodactyl.load_environment_only)
     * the upstream settings come from the environment only. MonoForge's own settings
     * (`monoforge:*`: shop, AI, privacy, sign-up, OAuth, captcha...) are loaded from the
     * database unless their environment variable is set, in which case the variable wins.
     */
    public function boot(ConfigRepository $config, Encrypter $encrypter, Log $log): void
    {
        $environmentOnly = (bool) $config->get('pterodactyl.load_environment_only', false);

        try {
            $values = Setting::values();
        } catch (QueryException $queryException) {
            $log->notice('A query exception was encountered while trying to load settings from the database: '.$queryException->getMessage());

            return;
        }

        // Phase 2 Task H: the driver chosen in the admin Email settings
        // (`mail:default`) is applied first, because it decides which of the
        // driver-specific keys below are loaded.
        $driver = Arr::get($values, 'settings::mail:default');
        if (! $environmentOnly && is_string($driver) && in_array($driver, self::MAIL_DRIVERS, true)) {
            $config->set('mail.default', $driver);
        }

        foreach ($this->keysToLoad($environmentOnly, $config->get('mail.default')) as $key) {
            $default = $config->get(str_replace(':', '.', $key));
            $value = Arr::get($values, 'settings::'.$key, $default);
            if (in_array($key, self::$encrypted, true) && is_string($value)) {
                try {
                    $value = $encrypter->decrypt($value);
                } catch (DecryptException) {
                    // Task H: never hand ciphertext to the Resend transport as a key.
                    if ($key === 'services:resend:key') {
                        $value = null;
                    }
                }
            }

            switch (is_string($value) ? mb_strtolower($value) : $value) {
                case 'true':
                case '(true)':
                    $value = true;
                    break;
                case 'false':
                case '(false)':
                    $value = false;
                    break;
                case 'empty':
                case '(empty)':
                    $value = '';
                    break;
                case 'null':
                case '(null)':
                    $value = null;
            }

            $config->set(str_replace(':', '.', $key), $this->cast($value, $default));
        }
    }

    /**
     * The keys to load from the database: all of them, with the mail keys of the SMTP or
     * Resend driver, or in environment-only mode just MonoForge's own, minus those whose
     * environment variable is set (the variable wins).
     *
     * @return list<string>
     */
    protected function keysToLoad(bool $environmentOnly, mixed $mailDriver = null): array
    {
        if ($environmentOnly) {
            $setInEnvironment = EnvironmentSettings::setInEnvironment();

            return array_values(array_filter(
                $this->keys,
                fn (string $key): bool => str_starts_with($key, 'monoforge:') && ! in_array($key, $setInEnvironment, true),
            ));
        }

        // Only set the email driver settings from the database if we
        // are configured using SMTP (or, for Task H, Resend) as the driver.
        return match ($mailDriver) {
            'smtp' => array_merge($this->keys, $this->emailKeys),
            'resend' => array_merge($this->keys, $this->resendKeys),
            default => $this->keys,
        };
    }

    /**
     * Settings are stored as strings, so a stored value takes the type of the
     * configuration value it replaces. Keys without a typed default keep the
     * stored value.
     */
    protected function cast(mixed $value, mixed $default): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match (true) {
            is_int($default) => (int) $value,
            is_float($default) => (float) $value,
            is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOL),
            default => $value,
        };
    }
}
