<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Migration;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use JsonException;
use Pterodactyl\Models\Setting;
use RuntimeException;

/**
 * Maps the settings an m12labs panel stored (Discord/Google login, Turnstile,
 * Resend, sender) onto MonoForge settings.
 *
 * m12labs encrypted secrets with its own APP_KEY via Crypt::encryptString,
 * sometimes several times over, so values are decrypted repeatedly while they
 * still look like a Laravel encrypted payload. MonoForge secrets are stored the
 * way SettingsServiceProvider reads them: Encrypter::encrypt() with this panel's key.
 */
class M12LabsSettingsImporter
{
    /**
     * Old m12labs keys this importer reads.
     *
     * @var list<string>
     */
    public const array SOURCE_KEYS = [
        'settings::modules:auth:discord:client_id',
        'settings::modules:auth:discord:client_secret',
        'settings::modules:auth:google:client_id',
        'settings::modules:auth:google:client_secret',
        'settings::modules:auth:captcha:provider',
        'settings::modules:auth:captcha:site_key',
        'settings::modules:auth:captcha:secret_key',
        'settings::modules:email:resend:api_key',
        'settings::modules:email:from_email',
        'settings::modules:email:from_name',
        'settings::modules:email:enabled',
        'settings::modules:email:primary',
        'settings::modules:email:backup',
        'settings::modules:email:smtp:host',
        'settings::app:name',
    ];

    /**
     * MonoForge keys (without the settings:: prefix) stored encrypted.
     *
     * @var list<string>
     */
    public const array ENCRYPTED_TARGETS = [
        'monoforge:auth:discord:client_secret',
        'monoforge:auth:google:client_secret',
        'monoforge:captcha:turnstile:secret_key',
        'services:resend:key',
    ];

    private const int MAX_DECRYPTION_LAYERS = 5;

    private const int MAX_ENCRYPTED_BYTES = 16384;

    /** @var list<string> Source keys whose value could not be decrypted. */
    private array $undecryptable = [];

    /** @var list<array{setting: string, decision: string, because: string}> */
    private array $decisions = [];

    public function __construct(private readonly Encrypter $source, private readonly Encrypter $target) {}

    public static function isSecret(string $key): bool
    {
        return in_array($key, self::ENCRYPTED_TARGETS, true);
    }

    /**
     * Turn the old rows into MonoForge settings (plaintext, keys without the
     * settings:: prefix).
     *
     * @param  array<string, string|null>  $rows  old key => stored value
     * @return array<string, array{value: string, from: string}>
     */
    public function map(array $rows): array
    {
        $this->undecryptable = [];
        $this->decisions = [];
        $plain = [];
        foreach (self::SOURCE_KEYS as $key) {
            $value = $rows[$key] ?? null;
            if (! is_string($value) || mb_trim($value) === '') {
                continue;
            }

            try {
                $value = mb_trim($this->decrypt($value));
            } catch (RuntimeException) {
                $this->undecryptable[] = $key;

                continue;
            }

            if ($value !== '') {
                $plain[$key] = $value;
            }
        }

        $settings = [];
        $set = function (string $target, string $source, ?string $value = null) use (&$settings, $plain): void {
            $settings[$target] = ['value' => $value ?? $plain[$source] ?? '', 'from' => $source];
        };

        foreach (['discord', 'google'] as $provider) {
            $id = "settings::modules:auth:{$provider}:client_id";
            $secret = "settings::modules:auth:{$provider}:client_secret";
            if (isset($plain[$id])) {
                $set("monoforge:auth:{$provider}:client_id", $id);
            }

            if (isset($plain[$secret])) {
                $set("monoforge:auth:{$provider}:client_secret", $secret);
            }

            if (isset($plain[$id], $plain[$secret])) {
                $set("monoforge:auth:{$provider}:enabled", $id, 'true');
            }
        }

        // m12labs only supported Cloudflare Turnstile, so its captcha keys are Turnstile keys.
        $siteKey = 'settings::modules:auth:captcha:site_key';
        $secretKey = 'settings::modules:auth:captcha:secret_key';
        if (isset($plain[$siteKey])) {
            $set('monoforge:captcha:turnstile:site_key', $siteKey);
        }

        if (isset($plain[$secretKey])) {
            $set('monoforge:captcha:turnstile:secret_key', $secretKey);
        }

        // Captcha is only switched on when m12labs had it on (or never stored a provider).
        $providerKey = 'settings::modules:auth:captcha:provider';
        $oldProvider = isset($plain[$providerKey]) ? mb_strtolower($plain[$providerKey]) : null;
        if (isset($plain[$siteKey], $plain[$secretKey])) {
            $enable = $oldProvider === null || $oldProvider === 'turnstile';
            if ($enable) {
                $set('monoforge:captcha:provider', $providerKey, 'turnstile');
            }

            $this->decide('monoforge:captcha:provider', $enable ? 'set to turnstile' : 'left unchanged', 'old captcha:provider = '.($oldProvider ?? '(absent)'));
        }

        // Resend only becomes the driver when m12labs sent mail with it.
        $resend = 'settings::modules:email:resend:api_key';
        if (isset($plain[$resend])) {
            $set('services:resend:key', $resend);

            $primary = isset($plain['settings::modules:email:primary']) ? mb_strtolower($plain['settings::modules:email:primary']) : null;
            $enabled = in_array(mb_strtolower($plain['settings::modules:email:enabled'] ?? ''), ['1', 'true', 'yes', 'on'], true);
            $onlyResend = ! isset($plain['settings::modules:email:smtp:host']);
            $useResend = $primary === 'resend' || ($primary === null && $enabled && $onlyResend);
            if ($useResend) {
                $set('mail:default', 'settings::modules:email:primary', 'resend');
            }

            $this->decide('mail:default', $useResend ? 'set to resend' : 'left unchanged', sprintf(
                'old email:primary = %s, enabled = %s, backup = %s, smtp host %s',
                $primary ?? '(absent)',
                $plain['settings::modules:email:enabled'] ?? '(absent)',
                $plain['settings::modules:email:backup'] ?? '(absent)',
                $onlyResend ? 'absent' : 'present',
            ));
        }

        if (isset($plain['settings::modules:email:from_email'])) {
            $set('mail:from:address', 'settings::modules:email:from_email');
        }

        if (isset($plain['settings::modules:email:from_name'])) {
            $set('mail:from:name', 'settings::modules:email:from_name');
        }

        // The command drops this when MonoForge already has a panel name.
        if (isset($plain['settings::app:name'])) {
            $set('app:name', 'settings::app:name');
        }

        return $settings;
    }

    /**
     * Driver/provider decisions made by the last map() call, with the (non-secret)
     * source values they were based on.
     *
     * @return list<array{setting: string, decision: string, because: string}>
     */
    public function decisions(): array
    {
        return $this->decisions;
    }

    /**
     * Source keys that looked encrypted but did not decrypt with the m12labs key
     * during the last map() call.
     *
     * @return list<string>
     */
    public function undecryptable(): array
    {
        return $this->undecryptable;
    }

    /**
     * Write the mapped settings, encrypting secrets with this panel's key.
     *
     * @param  array<string, array{value: string, from: string}>  $settings
     */
    public function apply(array $settings): void
    {
        foreach ($settings as $key => $setting) {
            $value = $setting['value'];
            if (in_array($key, self::ENCRYPTED_TARGETS, true)) {
                $value = $this->target->encrypt($value);
            }

            Setting::put('settings::'.$key, $value);
        }
    }

    /**
     * Peel off every encryption layer m12labs applied (at most five).
     *
     * @throws RuntimeException when a layer does not decrypt with the m12labs key
     */
    public function decrypt(string $value): string
    {
        for ($layer = 0; $layer < self::MAX_DECRYPTION_LAYERS && $this->looksEncrypted($value); $layer++) {
            throw_if(mb_strlen($value, '8bit') > self::MAX_ENCRYPTED_BYTES, RuntimeException::class, 'Encrypted value is too large.');

            try {
                $value = $this->source->decryptString($value);
            } catch (DecryptException $decryptException) {
                throw new RuntimeException('The value does not decrypt with the m12labs APP_KEY.', 0, $decryptException);
            }
        }

        // A value written with Crypt::encrypt() (serialized) rather than encryptString().
        if (preg_match('/^s:\d+:".*";$/s', $value) === 1) {
            $unserialized = @unserialize($value, ['allowed_classes' => false]);
            if (is_string($unserialized)) {
                return $this->decrypt($unserialized);
            }
        }

        return $value;
    }

    private function decide(string $setting, string $decision, string $because): void
    {
        $this->decisions[] = ['setting' => $setting, 'decision' => $decision, 'because' => $because];
    }

    /**
     * Whether the value is base64 JSON with Laravel's iv/value/mac (and optional tag).
     */
    private function looksEncrypted(string $value): bool
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }

        try {
            $payload = json_decode($decoded, true, 3, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! is_array($payload) || array_diff(array_keys($payload), ['iv', 'value', 'mac', 'tag']) !== []) {
            return false;
        }

        foreach (['iv', 'value', 'mac'] as $required) {
            if (! isset($payload[$required]) || ! is_string($payload[$required])) {
                return false;
            }
        }

        return true;
    }
}
