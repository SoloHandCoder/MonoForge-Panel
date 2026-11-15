<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing\Tebex;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Models\Setting;

/**
 * The Tebex Headless store the panel sells token packs through. The private key and the webhook
 * secret are stored encrypted and are never returned by the API or written to logs.
 */
class TebexSettings
{
    /** The addresses Tebex sends webhooks from, per the Tebex webhook documentation. */
    public const array DEFAULT_WEBHOOK_IPS = ['18.209.80.3', '54.87.231.232'];

    /** The client page that lists the player's tokens; Tebex returns players there after checkout. */
    public const string WALLET_PATH = '/account/wallet';

    private const string PREFIX = 'settings::monoforge:tebex:';

    /**
     * @return list<string>
     */
    public static function parseIps(string $value): array
    {
        $ips = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($ips === false ? [] : $ips));
    }

    public function publicToken(): string
    {
        return mb_trim((string) Setting::fetch(self::PREFIX.'public_token'));
    }

    public function privateKey(): string
    {
        return $this->secret('private_key');
    }

    public function webhookSecret(): string
    {
        return $this->secret('webhook_secret');
    }

    /** Whether players can buy token packs: a public token is set. */
    public function checkoutEnabled(): bool
    {
        return $this->publicToken() !== '';
    }

    /**
     * The addresses webhooks are accepted from; none stored means Tebex's documented addresses.
     *
     * @return list<string>
     */
    public function webhookIps(): array
    {
        $stored = Setting::fetch(self::PREFIX.'webhook_ips');
        if ($stored === null || mb_trim($stored) === '') {
            return self::DEFAULT_WEBHOOK_IPS;
        }

        return self::parseIps($stored);
    }

    /**
     * Save the settings. A null private key or webhook secret keeps the stored one.
     *
     * @param  list<string>  $webhookIps
     */
    public function save(string $publicToken, ?string $privateKey, ?string $webhookSecret, array $webhookIps): void
    {
        Setting::put(self::PREFIX.'public_token', mb_trim($publicToken));
        Setting::put(self::PREFIX.'webhook_ips', implode(',', $webhookIps));

        if ($privateKey !== null) {
            Setting::put(self::PREFIX.'private_key', Crypt::encrypt($privateKey));
        }

        if ($webhookSecret !== null) {
            Setting::put(self::PREFIX.'webhook_secret', Crypt::encrypt($webhookSecret));
        }
    }

    /** The URL to register under Developers > Webhooks in the Tebex creator panel. */
    public function webhookUrl(): string
    {
        return mb_rtrim(config()->string('app.url'), '/').'/api/webhooks/tebex';
    }

    /**
     * Where Tebex sends the player back to, such as `/account/wallet?tebex=complete&checkout=…`.
     * The checkout reference only says which of the player's own checkouts to show.
     */
    public function walletUrl(string $outcome, ?string $checkout = null): string
    {
        $url = mb_rtrim(config()->string('app.url'), '/').self::WALLET_PATH.'?tebex='.rawurlencode($outcome);

        return $checkout === null ? $url : $url.'&checkout='.rawurlencode($checkout);
    }

    private function secret(string $key): string
    {
        $stored = Setting::fetch(self::PREFIX.$key);
        if ($stored === null || $stored === '') {
            return '';
        }

        try {
            $value = Crypt::decrypt($stored);
        } catch (DecryptException) {
            // Encrypted with another APP_KEY: treat it as unset rather than using a garbled secret.
            return '';
        }

        return is_string($value) ? $value : '';
    }
}
