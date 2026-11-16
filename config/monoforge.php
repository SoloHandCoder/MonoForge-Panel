<?php

declare(strict_types=1);

/*
 * The settings that can also come from the environment, by setting key. With
 * APP_ENVIRONMENT_ONLY=true a variable that is set wins over the value stored in the
 * panel; a key whose variable is unset is still loaded from the database and stays
 * editable there.
 */
$environmentVariables = [
    'monoforge:update:mode' => 'MONOFORGE_UPDATE_MODE',
    'monoforge:auth:registration:enabled' => 'MONOFORGE_REGISTRATION_ENABLED',
    'monoforge:auth:discord:enabled' => 'DISCORD_LOGIN_ENABLED',
    'monoforge:auth:discord:client_id' => 'DISCORD_CLIENT_ID',
    'monoforge:auth:discord:client_secret' => 'DISCORD_CLIENT_SECRET',
    'monoforge:auth:google:enabled' => 'GOOGLE_LOGIN_ENABLED',
    'monoforge:auth:google:client_id' => 'GOOGLE_CLIENT_ID',
    'monoforge:auth:google:client_secret' => 'GOOGLE_CLIENT_SECRET',
    'monoforge:captcha:provider' => 'MONOFORGE_CAPTCHA_PROVIDER',
    'monoforge:captcha:turnstile:site_key' => 'TURNSTILE_SITE_KEY',
    'monoforge:captcha:turnstile:secret_key' => 'TURNSTILE_SECRET_KEY',
    'monoforge:privacy:hidden_ips' => 'MONOFORGE_HIDDEN_IPS',
    'monoforge:plugins:curseforge_key' => 'CURSEFORGE_API_KEY',
];

return [
    /*
    |--------------------------------------------------------------------------
    | Settings Backed By The Environment
    |--------------------------------------------------------------------------
    |
    | set_in_env is worked out when the configuration loads (and is cached with
    | it), so it stays right after "php artisan config:cache". A variable that is
    | empty counts as unset.
    |
    */
    'settings' => [
        'environment_variables' => $environmentVariables,
        // An empty variable ("DISCORD_CLIENT_ID=", as copied from an example) counts as unset.
        'set_in_env' => array_keys(array_filter($environmentVariables, fn (string $name): bool => ! in_array(env($name), [null, ''], true))),
    ],

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
        // Free trial servers (Shop > Trials). plan_ids: comma separated plan ids that can be
        // tried. hours: 24, 48 or 72. max_concurrent: trials running at once panel-wide.
        // require_verified: the account needs a linked Discord or Google account.
        'trials' => [
            'enabled' => false,
            'plan_ids' => '',
            'hours' => 48,
            'max_concurrent' => 10,
            'require_verified' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI assistant (phase 4)
    |--------------------------------------------------------------------------
    |
    | The admin AI Assistant > Settings tab stores these as "monoforge:ai:*".
    | ratio: AI tokens per panel token. free_tokens_monthly: AI tokens each user
    | gets free per calendar month (the admin assistant is never charged).
    | warn_percent: when the free allowance warning is shown. context_tokens:
    | above this estimated size the oldest messages are summarised.
    |
    */
    'ai' => [
        'enabled' => false,
        'server_enabled' => true,
        'admin_enabled' => true,
        'default_provider_id' => null,
        'ratio' => 1000,
        'free_tokens_monthly' => 50000,
        'warn_percent' => 80,
        'max_tool_iterations' => 8,
        'context_tokens' => 24000,
        'max_output_tokens' => 2048,
        // Phase 6: offer the AI crash doctor when a server crashes or fails to start.
        'crash_doctor_enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | One-click plugins and mods (phase 6)
    |--------------------------------------------------------------------------
    |
    | Modrinth and Hangar need no key. CurseForge is offered once a key from the
    | CurseForge for Studios console is set here or in Admin > Settings > Plugins,
    | which stores it encrypted as "monoforge:plugins:curseforge_key".
    |
    */
    'plugins' => [
        'curseforge_key' => env('CURSEFORGE_API_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    |
    | Activity entries never show the address of the panel host or a node. Beside
    | loopback, private and link-local ranges and each node's FQDN (resolved
    | through DNS), these IPs or CIDR ranges (one per line) are hidden as well.
    | The admin Settings page stores them as "monoforge:privacy:hidden_ips".
    |
    */
    'privacy' => [
        'hidden_ips' => env('MONOFORGE_HIDDEN_IPS', ''),
        // How long resolved node addresses are cached, in seconds.
        'node_dns_ttl' => 3600,
    ],
];
