<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Console\Kernel;
use Pterodactyl\Console\Scheduler;
use Pterodactyl\Exceptions\ApiErrorResponse;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Http\Controllers\Base\PublicHelpController;
use Pterodactyl\Http\Controllers\Base\PublicNoticeController;
use Pterodactyl\Http\Controllers\Base\PublicServerPageController;
use Pterodactyl\Http\Controllers\Base\PublicStatusController;
use Pterodactyl\Http\Controllers\Base\PublicStorePlansController;
use Pterodactyl\Http\Controllers\Webhooks\TebexWebhookController;
use Pterodactyl\Http\Middleware\Activity\TrackAPIKey;
use Pterodactyl\Http\Middleware\Api\Admin\AuthenticateAdmin;
use Pterodactyl\Http\Middleware\Api\Application\AuthenticateApplicationUser;
use Pterodactyl\Http\Middleware\Api\AuthenticateIPAccess;
use Pterodactyl\Http\Middleware\Api\Client\RequireClientApiKey;
use Pterodactyl\Http\Middleware\Api\Client\SubstituteClientBindings;
use Pterodactyl\Http\Middleware\Api\Daemon\DaemonAuthenticate;
use Pterodactyl\Http\Middleware\Api\IsValidJson;
use Pterodactyl\Http\Middleware\EnsureStatefulRequests;
use Pterodactyl\Http\Middleware\LanguageMiddleware;
use Pterodactyl\Http\Middleware\MaintenanceMiddleware;
use Pterodactyl\Http\Middleware\RedirectIfAuthenticated;
use Pterodactyl\Http\Middleware\RememberThemeFlags;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Pterodactyl\Http\Middleware\SetSecurityHeaders;
use Pterodactyl\Http\Middleware\TrustProxies;
use Pterodactyl\Http\Middleware\VerifyCaptcha;
use Pterodactyl\Services\ServerPages\ServerPageSlug;
use Pterodactyl\Services\Themes\ThemeResolver;
use Symfony\Component\HttpKernel\Exception\HttpException;

$app = Application::configure(basePath: $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__))
    ->withRouting(using: function (): void {
        Route::middleware('web')->group(function (): void {
            Route::middleware(['auth.session', RequireTwoFactorAuthentication::class])
                ->group(base_path('routes/base.php'));

            Route::middleware('guest')->prefix('/auth')->group(base_path('routes/auth.php'));
        });

        Route::middleware(['api'])->group(function (): void {
            Route::middleware(['application-api', 'throttle:api.application'])
                ->prefix('/api/application')
                ->scopeBindings()
                ->group(base_path('routes/api-application.php'));

            Route::middleware(['client-api', 'throttle:api.client'])
                ->prefix('/api/client')
                ->scopeBindings()
                ->group(base_path('routes/api-client.php'));

            Route::middleware(['admin-api', 'throttle:api.admin'])
                ->prefix('/api/admin')
                ->scopeBindings()
                ->group(base_path('routes/api-admin.php'));
        });

        // The sign-in pages show active announcements before anyone has signed in.
        Route::middleware('throttle:60,1')
            ->get('/api/notices/public', PublicNoticeController::class)
            ->name('api.notices.public');

        // The public status page's data: no session, cached for 30 seconds.
        Route::middleware('throttle:60,1')
            ->get('/api/public/status', PublicStatusController::class)
            ->name('api.public.status');

        // The help center's articles: no session, not cached so an admin's change shows at once.
        Route::middleware('throttle:60,1')
            ->get('/api/public/help', PublicHelpController::class)
            ->name('api.public.help');

        // The public homepage's plans: no session or cookies, cached for a minute.
        Route::middleware('throttle:60,1')
            ->get('/api/public/store/plans', PublicStorePlansController::class)
            ->name('api.public.store.plans');

        // The server discovery page at /servers: no session, one query a page, cached for 30 seconds.
        Route::middleware('throttle:60,1')
            ->get('/api/public/servers', [PublicServerPageController::class, 'index'])
            ->name('api.public.servers.index');

        // The shareable public server pages at /s/{slug}: no session, cached for 30 seconds. Votes
        // and the wake button set or read only the visitor cookie, kept as a keyed hash.
        Route::middleware('throttle:60,1')
            ->get('/api/public/servers/{slug}', [PublicServerPageController::class, 'show'])
            ->where('slug', ServerPageSlug::PATTERN)
            ->name('api.public.servers.show');
        Route::middleware('throttle:10,1')
            ->post('/api/public/servers/{slug}/vote', [PublicServerPageController::class, 'vote'])
            ->where('slug', ServerPageSlug::PATTERN)
            ->name('api.public.servers.vote');
        Route::middleware('throttle:10,1')
            ->post('/api/public/servers/{slug}/wake', [PublicServerPageController::class, 'wake'])
            ->where('slug', ServerPageSlug::PATTERN)
            ->name('api.public.servers.wake');

        // Tebex payment webhooks: no session, no CSRF, checked by source address and signature instead.
        // The address is $request->ip(): CF-Connecting-IP when the peer is the local tunnel (see
        // TrustProxies). Never add 127.0.0.1 to the allowlist: every tunnelled request comes from it.
        Route::middleware('throttle:tebex.webhook')
            ->post('/api/webhooks/tebex', TebexWebhookController::class)
            ->name('api.webhooks.tebex');

        Route::middleware('daemon')
            ->prefix('/api/remote')
            ->scopeBindings()
            ->group(base_path('routes/api-remote.php'));
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SetSecurityHeaders::class);

        // Client addresses come from CF-Connecting-IP / X-Forwarded-For only behind a trusted proxy.
        $middleware->replace(FrameworkTrustProxies::class, TrustProxies::class);

        $middleware->trimStrings(except: [
            'password',
            'password_confirmation',
            fn (Request $request): bool => $request->is('api/client/servers/*/files', 'api/client/servers/*/files/*'),
        ]);

        $middleware->preventRequestForgery(except: ['remote/*', 'daemon/*', 'api/webhooks/*']);

        $middleware->redirectGuestsTo('/auth/login');

        $middleware->priority([SubstituteClientBindings::class]);

        $middleware->web(append: [LanguageMiddleware::class, RememberThemeFlags::class]);

        // Written by the SPA, so it is never encrypted.
        $middleware->encryptCookies(except: [ThemeResolver::SCHEME_COOKIE]);

        $middleware->group('api', [
            EnsureStatefulRequests::class,
            'auth:sanctum',
            IsValidJson::class,
            TrackAPIKey::class,
            RequireTwoFactorAuthentication::class,
            AuthenticateIPAccess::class,
        ]);

        $middleware->group('application-api', [SubstituteBindings::class, AuthenticateApplicationUser::class]);
        $middleware->group('client-api', [SubstituteClientBindings::class, RequireClientApiKey::class]);
        $middleware->group('admin-api', [SubstituteBindings::class, AuthenticateAdmin::class]);
        $middleware->group('daemon', [SubstituteBindings::class, DaemonAuthenticate::class]);

        $middleware->alias([
            'guest' => RedirectIfAuthenticated::class,
            // reCAPTCHA or Cloudflare Turnstile, whichever the panel uses.
            'captcha' => VerifyCaptcha::class,
            'recaptcha' => VerifyCaptcha::class,
            'node.maintenance' => MaintenanceMiddleware::class,
        ]);
    })
    ->withSchedule(new Scheduler)
    ->withExceptions(function (Exceptions $exceptions): void {
        if (config('app.exceptions.report_all', false)) {
            $exceptions->stopIgnoring([
                AuthenticationException::class,
                AuthorizationException::class,
                HttpException::class,
                ModelNotFoundException::class,
                TokenMismatchException::class,
                ValidationException::class,
            ]);
        } else {
            // A failed Wings call is an HTTP exception that still logs its cause.
            $exceptions->stopIgnoring(HttpException::class)
                ->dontReportWhen(fn (Throwable $e): bool => $e instanceof HttpException && ! $e instanceof DaemonConnectionException);
        }

        $exceptions->dontFlash(['token', 'secret', 'password', 'password_confirmation']);

        $exceptions->render(fn (ValidationException $e, Request $request): ?JsonResponse => $request->expectsJson() ? ApiErrorResponse::validation($e) : null);

        $exceptions->render(fn (AuthenticationException $e, Request $request): JsonResponse|RedirectResponse => $request->expectsJson()
            ? ApiErrorResponse::render($e)
            : redirect()->guest('/auth/login'));

        // These answer API routes in JSON even when the client does not ask for it.
        $exceptions->render(fn (DisplayException|DaemonConnectionException $e, Request $request): ?JsonResponse => $request->is('api/*')
            ? ApiErrorResponse::render($e)
            : null);

        $exceptions->render(fn (Throwable $e, Request $request): ?JsonResponse => $request->expectsJson() && ! $e instanceof HttpResponseException
            ? ApiErrorResponse::render($e)
            : null);
    })
    ->create();

// The panel's console kernel adds extension commands after its own. Artisan resolves the
// kernel before any provider registers, so it is bound here rather than in a provider.
$app->singleton(ConsoleKernelContract::class, Kernel::class);

if (isset($_ENV['APP_STORAGE_PATH'])) {
    $app->useStoragePath($_ENV['APP_STORAGE_PATH']);
}

return $app;
