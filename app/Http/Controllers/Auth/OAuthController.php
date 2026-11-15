<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Enum\OAuthProvider;
use Pterodactyl\Exceptions\Service\Auth\OAuthException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserOAuthAccount;
use Pterodactyl\Providers\AppServiceProvider;
use Pterodactyl\Services\Auth\OAuth\OAuthAccountService;
use Pterodactyl\Services\Auth\OAuth\OAuthClient;
use Pterodactyl\Services\Auth\OAuth\OAuthPending;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Continue with Discord/Google": starts the authorization request and handles the
 * provider's callback. The callback URLs are registered with the providers; keep them.
 */
class OAuthController extends Controller
{
    /** The session flash the sign-in and account pages read the outcome from. */
    public const string RESULT_KEY = 'oauth_result';

    public function __construct(
        private readonly OAuthClient $client,
        private readonly OAuthAccountService $accounts,
    ) {}

    /**
     * Start signing in with a provider and return the URL to send the browser to.
     */
    public function start(string $provider): JsonResponse
    {
        $provider = $this->enabledProvider($provider);

        return new JsonResponse(['url' => $this->client->begin($provider, OAuthPending::INTENT_LOGIN)]);
    }

    /**
     * The provider sends the browser back here with a code (or an error) and the state.
     */
    public function callback(Request $request, string $provider, CompletesLogins $logins): RedirectResponse
    {
        $provider = OAuthProvider::tryFrom($provider) ?? throw new NotFoundHttpException;
        $pending = $this->client->pull($provider, $request->query('state'));
        $label = $provider->label();

        if (! $pending instanceof OAuthPending) {
            return $this->fail(Auth::check(), "That {$label} sign in request expired or was not started here. Please try again.");
        }

        if (! $provider->enabled()) {
            return $this->fail($pending->isLink(), "Signing in with {$label} is turned off.");
        }

        $code = $request->query('code');
        if ($request->filled('error') || ! is_string($code) || $code === '') {
            return $this->fail($pending->isLink(), "{$label} sign in was cancelled.");
        }

        // A link only goes to the user who started it, checked before the code is exchanged.
        $linkUser = $request->user();
        if ($pending->isLink() && (! $linkUser instanceof User || $linkUser->id !== $pending->userId)) {
            return $this->fail(true, "Your session changed before {$label} finished. Please try linking again.");
        }

        try {
            $identity = $this->client->identity($pending, $code);

            if ($pending->isLink() && $linkUser instanceof User) {
                $this->accounts->link($linkUser, $identity);

                return $this->succeed("Your {$label} account is now linked.");
            }

            if (Auth::check()) {
                return new RedirectResponse('/');
            }

            $account = $this->accounts->find($identity);
            if ($account instanceof UserOAuthAccount) {
                $this->accounts->refresh($account, $identity);
                $user = $account->user;
            } elseif (filter_var(config('monoforge.auth.registration.enabled'), FILTER_VALIDATE_BOOL)) {
                $limit = $this->registrationLimit($request);
                if (RateLimiter::tooManyAttempts($limit['key'], $limit['max'])) {
                    return $this->fail(false, 'Too many accounts have been created from your network recently. Please try again later.');
                }

                $user = $this->accounts->register($identity);
                RateLimiter::hit($limit['key'], $limit['decay']);
            } else {
                return $this->fail(false, "No MonoForge account is linked to this {$label} account.");
            }
        } catch (OAuthException $oAuthException) {
            return $this->fail($pending->isLink(), $oAuthException->getMessage());
        }

        // Accounts with two-factor authentication still have to pass the checkpoint.
        $result = $logins->complete($user);
        if ($result->complete) {
            return new RedirectResponse($result->intended);
        }

        $request->session()->flash(self::RESULT_KEY, ['status' => 'checkpoint', 'token' => $result->confirmationToken]);

        return new RedirectResponse('/auth/login');
    }

    /**
     * The per-IP allowance of the `registration` limiter, so accounts made through a provider
     * count against the same hourly budget as the sign-up form. The key matches the one the
     * throttle middleware builds for it.
     *
     * @return array{key: string, max: int, decay: int}
     */
    private function registrationLimit(Request $request): array
    {
        $limit = AppServiceProvider::registrationIpLimit($request);

        return ['key' => md5('registration'.'ip:'.$request->ip()), 'max' => $limit->maxAttempts, 'decay' => $limit->decaySeconds];
    }

    private function enabledProvider(string $provider): OAuthProvider
    {
        $provider = OAuthProvider::tryFrom($provider);
        throw_if(! $provider instanceof OAuthProvider || ! $provider->enabled(), NotFoundHttpException::class);

        return $provider;
    }

    private function fail(bool $toAccount, string $message): RedirectResponse
    {
        session()->flash(self::RESULT_KEY, ['status' => 'error', 'message' => $message]);

        return new RedirectResponse($toAccount ? '/account' : '/auth/login');
    }

    private function succeed(string $message): RedirectResponse
    {
        session()->flash(self::RESULT_KEY, ['status' => 'success', 'message' => $message]);

        return new RedirectResponse('/account');
    }
}
