<?php

declare(strict_types=1);

namespace Pterodactyl\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Knuckles\Scribe\Scribe;
use Pterodactyl\Contracts\Captcha\VerifiesCaptcha;
use Pterodactyl\Enum\ResourceLimit;
use Pterodactyl\Extensions\Illuminate\Auth\Passwords\PasswordBrokerManager;
use Pterodactyl\Extensions\Mail\ResendTransport;
use Pterodactyl\Extensions\Scribe\Support\PathParameterNames;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Mount;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Schedule;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Tag;
use Pterodactyl\Models\Task;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserSSHKey;
use Pterodactyl\Services\Activity\InfrastructureIps;
use Pterodactyl\Services\Captcha\HttpCaptchaVerifier;
use Pterodactyl\Services\Daemon\DaemonManager;
use Pterodactyl\Services\Servers\EnvironmentService;
use ReflectionFunctionAbstract;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /** Three accounts an hour per IP; the Discord/Google sign-up counts against the same budget. */
    public static function registrationIpLimit(Request $request): Limit
    {
        return Limit::perHour(3)->by('ip:'.$request->ip());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        // The password policy every password rule applies (see CreateUser::passwordRules()).
        Password::defaults(Password::min(8));

        Route::model('database', Database::class);

        $this->configureRateLimiting();

        // Surface lazy loads, discarded fills, and missing-attribute reads as
        // exceptions everywhere except production, where a missed spot should log a
        // query rather than take the panel down.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Refuse migrate:fresh and friends against the production database.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // All framework-created dates (model casts included) are immutable; in-place
        // mutation of a shared timestamp is never something we want.
        Date::use(CarbonImmutable::class);

        // Read once: every request boots through here, and each read is a cache store round trip.
        $version = $this->versionData();
        View::share('appVersion', $version['version']);
        View::share('appIsGit', $version['is_git']);

        Paginator::useBootstrap();

        // If the APP_URL value is set with https:// make sure we force it here. Theoretically
        // this should just work with the proxy logic, but there are a lot of cases where it
        // doesn't, and it triggers a lot of support requests, so lets just head it off here.
        //
        // @see https://github.com/pterodactyl/panel/issues/3623
        if (Str::startsWith(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        if ($this->app->runningConsoleCommand('scribe:generate')) {
            Scribe::normalizeEndpointUrlUsing(
                fn (string $url, IlluminateRoute $route, ReflectionFunctionAbstract $method): string => PathParameterNames::normalize($route, $method),
            );
        }

        Relation::enforceMorphMap([
            'allocation' => Allocation::class,
            'api_key' => ApiKey::class,
            'backup' => Backup::class,
            'database' => Database::class,
            'database_host' => DatabaseHost::class,
            'egg' => Egg::class,
            'egg_variable' => EggVariable::class,
            'location' => Location::class,
            'mount' => Mount::class,
            'node' => Node::class,
            'schedule' => Schedule::class,
            'server' => Server::class,
            'server_database' => Database::class,
            'ssh_key' => UserSSHKey::class,
            'tag' => Tag::class,
            'task' => Task::class,
            'user' => User::class,
        ]);
    }

    /**
     * Register application service providers.
     */
    public function register(): void
    {
        $this->app->singleton(DaemonManager::class);

        $this->app->bind(VerifiesCaptcha::class, HttpCaptchaVerifier::class);

        // Keys registered through setEnvironmentKey() must survive to every consumer,
        // so the service is shared rather than rebuilt on each resolve.
        $this->app->singleton(EnvironmentService::class);

        // Resolved node addresses are kept for the rest of the request (or queued job).
        $this->app->scoped(InfrastructureIps::class);

        $this->app->extend('auth.password', fn (): PasswordBrokerManager => new PasswordBrokerManager($this->app));

        // Resend over its HTTP API without the Resend SDK. The key is read when the
        // transport is built, after SettingsServiceProvider has applied stored settings.
        $this->callAfterResolving('mail.manager', function (MailManager $manager): void {
            $manager->extend('resend', function (array $config): ResendTransport {
                $key = $config['key'] ?? config('services.resend.key');

                return new ResendTransport($this->app->make(HttpFactory::class), is_string($key) ? $key : '');
            });
        });

        // Settings stored in the database. With APP_ENVIRONMENT_ONLY the provider still runs,
        // but only applies MonoForge's own settings whose environment variable is unset.
        if (! $this->app->environment('testing')) {
            $this->app->register(SettingsServiceProvider::class);
        }
    }

    /**
     * Return version information for the footer.
     *
     * @return array{version: string, is_git: bool}
     */
    protected function versionData(): array
    {
        return Cache::remember('git-version', 5, function (): array {
            if (file_exists(base_path('.git/HEAD'))) {
                $headContents = file_get_contents(base_path('.git/HEAD'));

                if ($headContents !== false) {
                    $head = explode(' ', $headContents);

                    if (array_key_exists(1, $head)) {
                        $path = base_path('.git/'.mb_trim($head[1]));
                    }
                }
            }

            if (isset($path) && file_exists($path)) {
                $refContents = file_get_contents($path);

                if ($refContents !== false) {
                    return [
                        'version' => mb_substr($refContents, 0, 8),
                        'is_git' => true,
                    ];
                }
            }

            return [
                'version' => config()->string('app.version'),
                'is_git' => false,
            ];
        });
    }

    /**
     * Rate limiters for authentication and the three APIs.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('authentication', function (Request $request): Limit {
            if ($request->route()?->named('auth.post.forgot-password')) {
                return Limit::perMinute(2)->by($request->ip());
            }

            return Limit::perMinute(10)->by($request->ip());
        });

        // Sign-up: 3 accounts an hour per IP and 1 attempt an hour per submitted email.
        // Runs after the captcha, so failed captchas do not use anyone's attempts. Forms
        // the validation rejects do not count either, so a taken username or a typo does
        // not lock anyone out, except rejections of the email itself (probing for
        // registered addresses), which count against that email.
        RateLimiter::for('registration', function (Request $request): array {
            $email = mb_strtolower(mb_trim($request->string('email')->toString()));

            return [
                self::registrationIpLimit($request)
                    ->after(fn (Response $response): bool => $response->getStatusCode() !== Response::HTTP_UNPROCESSABLE_ENTITY),
                Limit::perHour(1)->by('email:'.$email)
                    ->after(fn (Response $response): bool => $response->getStatusCode() !== Response::HTTP_UNPROCESSABLE_ENTITY
                        || $this->rejectsField($response, 'email')),
            ];
        });

        RateLimiter::for('api.client', fn (Request $request): Limit => $this->apiLimit($request, 'client'));
        RateLimiter::for('api.application', fn (Request $request): Limit => $this->apiLimit($request, 'application'));
        RateLimiter::for('api.admin', fn (Request $request): Limit => $this->apiLimit($request, 'admin'));

        // "Check now" for MonoForge updates calls GitHub each time, so it is kept to 6 a minute.
        RateLimiter::for('monoforge.update-check', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(6)->by($user instanceof User ? $user->uuid : (string) $request->ip());
        });

        // Tebex webhooks: 120 a minute per client address (CF-Connecting-IP behind the tunnel, see
        // TrustProxies), so a flood from one address cannot use up Tebex's own deliveries.
        RateLimiter::for('tebex.webhook', fn (Request $request): Limit => Limit::perMinute(120)->by('tebex:'.$request->ip()));

        // The editor's file-name search walks the tree on Wings: 10 a minute per user and server.
        RateLimiter::for('files.search', function (Request $request): Limit {
            $user = $request->user();
            $server = $request->route('server');
            $serverKey = $server instanceof Server ? $server->uuid : (is_string($server) ? $server : '');

            return Limit::perMinute(10)->by('files-search:'.($user instanceof User ? $user->id : (string) $request->ip()).':'.$serverKey);
        });

        // Store purchases: 3 attempts a minute per player, so one player retrying against a hung
        // node cannot keep the store-wide capacity lock busy for everyone else.
        RateLimiter::for('store.purchase', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(3)->by('store-purchase:'.($user instanceof User ? $user->id : (string) $request->ip()));
        });

        ResourceLimit::boot();
    }

    /**
     * Whether a validation error response names the given field.
     */
    private function rejectsField(Response $response, string $field): bool
    {
        $body = json_decode((string) $response->getContent(), true);
        $errors = is_array($body) ? ($body['errors'] ?? null) : null;
        if (! is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            if (is_array($error) && Arr::get($error, 'meta.source_field') === $field) {
                return true;
            }
        }

        return false;
    }

    private function apiLimit(Request $request, string $name): Limit
    {
        $user = $request->user();
        $key = $user instanceof User ? $user->uuid : $request->ip();

        return Limit::perMinutes(config()->integer("http.rate_limit.{$name}_period"), config()->integer("http.rate_limit.{$name}"))->by($key);
    }
}
