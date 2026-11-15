<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | MonoForge Self-Update
    |--------------------------------------------------------------------------
    |
    | Every push to the channel branch is built by GitHub Actions into a release
    | tagged "build-<short sha>" on this repository. The panel only ever downloads
    | that release's assets over HTTPS and verifies their sha256 before using them.
    |
    | mode: "off" (no checks), "notify" (check daily and show updates in the admin
    | area) or "auto" (check hourly and install new builds). The admin Settings page
    | stores it as the "monoforge:update:mode" setting, which overrides this value.
    |
    */
    'update' => [
        'repo' => 'SoloHandCoder/MonoForge-Panel',
        'channel' => 'main',
        'mode' => env('MONOFORGE_UPDATE_MODE', 'notify'),
        // How long a successful release lookup is cached, in minutes.
        'cache_minutes' => 30,
        // How many code and database backups to keep in storage/app/updates.
        'keep_backups' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sign-up and login providers
    |--------------------------------------------------------------------------
    |
    | Whether visitors may create their own account at /auth/register. The admin
    | Settings page stores it as "monoforge:auth:registration:enabled".
    |
    | Discord and Google sign in (Settings > Login providers, stored as
    | "monoforge:auth:{provider}:*"). A provider is offered only while it is
    | enabled and has both a client ID and a client secret.
    |
    */
    'auth' => [
        'registration' => [
            'enabled' => (bool) env('MONOFORGE_REGISTRATION_ENABLED', false),
        ],
        'discord' => [
            'enabled' => (bool) env('DISCORD_LOGIN_ENABLED', false),
            'client_id' => env('DISCORD_CLIENT_ID', ''),
            'client_secret' => env('DISCORD_CLIENT_SECRET', ''),
        ],
        'google' => [
            'enabled' => (bool) env('GOOGLE_LOGIN_ENABLED', false),
            'client_id' => env('GOOGLE_CLIENT_ID', ''),
            'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Captcha
    |--------------------------------------------------------------------------
    |
    | provider: "none", "recaptcha" or "turnstile" (Cloudflare). Left empty, the
    | legacy "recaptcha.enabled" switch decides between reCAPTCHA and none. The
    | reCAPTCHA keys live in config/recaptcha.php.
    |
    */
    'captcha' => [
        'provider' => env('MONOFORGE_CAPTCHA_PROVIDER'),
        'turnstile' => [
            'site_key' => env('TURNSTILE_SITE_KEY', ''),
            'secret_key' => env('TURNSTILE_SECRET_KEY', ''),
            'domain' => env('TURNSTILE_DOMAIN', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Token shop (phase 3)
    |--------------------------------------------------------------------------
    |
    | The admin Shop > Limits tab stores these as "monoforge:shop:*" settings.
    | grace_days: days a suspended shop server is kept before it is deleted
    | (0 deletes it at suspension). low_balance_days: how long before a renewal
    | a player with too few tokens is warned. limits: the most memory (MB), disk
    | (MB) and CPU (percent) the shop sells across all shop servers; 0 = no limit.
    |
    */
    'shop' => [
        'enabled' => false,
        'grace_days' => 7,
        'low_balance_days' => 3,
        'limits' => [
            'memory_mb' => 0,
            'disk_mb' => 0,
            'cpu_percent' => 0,
        ],
    ],
];
