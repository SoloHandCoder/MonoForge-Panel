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
];
