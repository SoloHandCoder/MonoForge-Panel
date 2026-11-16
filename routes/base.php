<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pterodactyl\Enum\LegalDocument;
use Pterodactyl\Http\Controllers\Base\ExtensionFileController;
use Pterodactyl\Http\Controllers\Base\IndexController;
use Pterodactyl\Http\Controllers\Base\LegalPageController;
use Pterodactyl\Http\Controllers\Base\LocaleController;
use Pterodactyl\Http\Controllers\Base\PlayitSwitchScriptController;
use Pterodactyl\Http\Controllers\Base\ServerPageBannerController;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Pterodactyl\Services\Extensions\ExtensionSettingFiles;
use Pterodactyl\Services\ServerPages\ServerPageBanners;
use Pterodactyl\Services\ServerPages\ServerPageSlug;

Route::get('/', [IndexController::class, 'index'])->name('index')->fallback();
Route::get('/account', [IndexController::class, 'index'])
    ->withoutMiddleware(RequireTwoFactorAuthentication::class)
    ->name('account');

Route::get('/locales/locale.json', LocaleController::class)
    ->withoutMiddleware(['auth', RequireTwoFactorAuthentication::class])
    ->where('namespace', '.*');

// The script a node runs to switch playit.gg to the agent the panel set up: public, it holds no secret.
Route::get('/scripts/playit-switch-agent.sh', PlayitSwitchScriptController::class)
    ->withoutMiddleware(['auth', RequireTwoFactorAuthentication::class])
    ->name('scripts.playit-switch-agent');

// The Terms of Service, Privacy Policy and Refund Policy: public, and reachable by a user
// who still has to set up two-factor authentication.
Route::get('/legal/{document}', LegalPageController::class)
    ->withoutMiddleware(['auth', RequireTwoFactorAuthentication::class])
    ->whereIn('document', LegalDocument::values())
    ->name('legal');

// The public status page, readable without signing in.
Route::get('/status', [IndexController::class, 'index'])
    ->withoutMiddleware(['auth', RequireTwoFactorAuthentication::class])
    ->name('status');

// The shareable public server pages, readable without signing in. The SPA renders the page; the
// server adds its title and Open Graph tags for link previews.
Route::get('/s/{slug}', [IndexController::class, 'serverPage'])
    ->withoutMiddleware(['auth', RequireTwoFactorAuthentication::class])
    ->where('slug', ServerPageSlug::PATTERN)
    ->name('server-page');

// A public server page's banner image. Stateless, like the extension files below.
Route::get('/s/{slug}/banner/{file}', ServerPageBannerController::class)
    ->withoutMiddleware(['web', 'auth.session', RequireTwoFactorAuthentication::class])
    ->where('slug', ServerPageSlug::PATTERN)
    ->where('file', ServerPageBanners::NAME_PATTERN)
    ->name('server-page.banner');

// Files uploaded through extension `file` settings. Public and stateless: no
// session, no cookies, so the immutable cache headers are safe behind a proxy.
Route::get('/'.ExtensionSettingFiles::ROUTE_PREFIX.'/{extension}/{file}', ExtensionFileController::class)
    ->withoutMiddleware(['web', 'auth.session', RequireTwoFactorAuthentication::class])
    ->where('extension', '[a-z][a-z0-9-]{0,47}')
    ->where('file', '[a-f0-9]{40}\.[a-z0-9]{2,5}')
    ->name('extensions.files');

// The SPA catch-all is a fallback so that routes registered after it - an extension's
// /extensions/<id> and claimed root path routes - are matched first.
Route::get('/{react}', [IndexController::class, 'index'])
    ->where('react', '^(?!(\/)?(api|auth|daemon)).+')
    ->fallback();
