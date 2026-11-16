<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pterodactyl\Enum\LegalDocument;
use Pterodactyl\Http\Controllers\Base\ExtensionFileController;
use Pterodactyl\Http\Controllers\Base\GatewayScriptController;
use Pterodactyl\Http\Controllers\Base\HelpPageController;
use Pterodactyl\Http\Controllers\Base\IndexController;
use Pterodactyl\Http\Controllers\Base\LegalPageController;
use Pterodactyl\Http\Controllers\Base\LocaleController;
use Pterodactyl\Http\Controllers\Base\MinecraftRouterScriptController;
use Pterodactyl\Http\Controllers\Base\PlayitSwitchScriptController;
use Pterodactyl\Http\Controllers\Base\ReferralLinkController;
use Pterodactyl\Http\Controllers\Base\ServerDiscoveryPageController;
use Pterodactyl\Http\Controllers\Base\ServerPageBannerController;
use Pterodactyl\Http\Controllers\Base\SitemapController;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Pterodactyl\Services\Extensions\ExtensionSettingFiles;
use Pterodactyl\Services\Help\HelpArticles;
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

// The game gateway script for a VPS and a node (scripts/monoforge/gateway.sh): public, it holds no secret.
Route::get('/scripts/gateway.sh', GatewayScriptController::class)
    ->withoutMiddleware(['auth', RequireTwoFactorAuthentication::class])
    ->name('scripts.gateway');

// The shared Minecraft entrance router for a node (scripts/monoforge/mc-router.sh): public, it holds no secret.
Route::get('/scripts/mc-router.sh', MinecraftRouterScriptController::class)
    ->withoutMiddleware(['auth', RequireTwoFactorAuthentication::class])
    ->name('scripts.mc-router');

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

// The help center and its articles: public and indexable; the server adds each page's title and
// description, the SPA renders the articles.
Route::get('/help', [HelpPageController::class, 'index'])
    ->withoutMiddleware(['auth', RequireTwoFactorAuthentication::class])
    ->name('help');
Route::get('/help/{article}', [HelpPageController::class, 'show'])
    ->withoutMiddleware(['auth', RequireTwoFactorAuthentication::class])
    ->where('article', HelpArticles::SLUG_PATTERN)
    ->name('help.article');

// A referral link: remembers the code for 30 days and sends the visitor on to sign up.
Route::get('/r/{code}', ReferralLinkController::class)
    ->withoutMiddleware(['auth', RequireTwoFactorAuthentication::class])
    ->where('code', '[A-Za-z0-9]{1,16}')
    ->middleware('throttle:30,1')
    ->name('referral');

// The shareable public server pages, readable without signing in. The SPA renders the page; the
// server adds its title and Open Graph tags for link previews.
Route::get('/s/{slug}', [IndexController::class, 'serverPage'])
    ->withoutMiddleware(['auth', RequireTwoFactorAuthentication::class])
    ->where('slug', ServerPageSlug::PATTERN)
    ->name('server-page');

// The server discovery page: the published server pages that are listed, readable without
// signing in and indexable; the server adds the title and description, the SPA renders the list.
Route::get('/servers', ServerDiscoveryPageController::class)
    ->withoutMiddleware(['auth', RequireTwoFactorAuthentication::class])
    ->name('servers');

// A public server page's banner image. Stateless, like the extension files below.
Route::get('/s/{slug}/banner/{file}', ServerPageBannerController::class)
    ->withoutMiddleware(['web', 'auth.session', RequireTwoFactorAuthentication::class])
    ->where('slug', ServerPageSlug::PATTERN)
    ->where('file', ServerPageBanners::NAME_PATTERN)
    ->name('server-page.banner');

// The sitemap of the public pages. Stateless: no session or cookies, so it can be cached.
Route::get('/sitemap.xml', SitemapController::class)
    ->withoutMiddleware(['web', 'auth.session', RequireTwoFactorAuthentication::class])
    ->middleware('throttle:60,1')
    ->name('sitemap');

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
