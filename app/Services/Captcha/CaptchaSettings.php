<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Captcha;

/**
 * Which captcha protects the panel's sign-in forms, and its public site key. The
 * "monoforge:captcha:provider" setting picks the provider; while it is unset the
 * legacy "recaptcha:enabled" switch decides between reCAPTCHA and none.
 */
final class CaptchaSettings
{
    public const string NONE = 'none';

    public const string RECAPTCHA = 'recaptcha';

    public const string TURNSTILE = 'turnstile';

    public const array PROVIDERS = [self::NONE, self::RECAPTCHA, self::TURNSTILE];

    /**
     * @return self::NONE|self::RECAPTCHA|self::TURNSTILE
     */
    public static function provider(): string
    {
        $configured = config('monoforge.captcha.provider');

        return match ($configured) {
            self::NONE, self::RECAPTCHA, self::TURNSTILE => $configured,
            default => config()->boolean('recaptcha.enabled') ? self::RECAPTCHA : self::NONE,
        };
    }

    /**
     * The public key the browser widget of the active provider needs.
     */
    public static function siteKey(): string
    {
        return match (self::provider()) {
            self::RECAPTCHA => config()->string('recaptcha.website_key'),
            self::TURNSTILE => config()->string('monoforge.captcha.turnstile.site_key'),
            default => '',
        };
    }
}
