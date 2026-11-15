<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Enum\OAuthProvider;
use Pterodactyl\Exceptions\Service\Auth\OAuthException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserOAuthAccount;
use Pterodactyl\Providers\AppServiceProvider;
use Pterodactyl\Services\Auth\OAuth\OAuthAccountService;
use Pterodactyl\Services\Auth\OAuth\OAuthClient;
use Pterodactyl\Services\Auth\OAuth\OAuthIdentity;
use Pterodactyl\Services\Auth\OAuth\OAuthPending;
use Pterodactyl\Services\Auth\OAuth\OAuthRegistration;
use Pterodactyl\Services\Legal\LegalSettings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Continue with Discord/Google": starts the authorization request and handles the
 * provider's callback. The callback URLs are registered with the providers; keep them.
 */
class OAuthController extends Controller
{
    /** The session flash the sign-in and account pages read the outcome from. */
    public const string RESULT_KEY = 'oauth_result';

    /** Where a Discord/Google sign-up accepts the terms before its account is made. */
    public const string CONSENT_PATH = '/auth/register/consent';

    private const string TOO_MANY_SIGN_UPS = 'Too many accounts have been created from your network recently. Please try again later.';

    private const string SIGN_UP_EXPIRED = 'That sign up expired or was replaced. Please continue with Discord or Google again.';

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
                if ($this->registrationLimited($request)) {
                    return $this->fail(false, self::TOO_MANY_SIGN_UPS);
                }

                $this->accounts->ensureRegistrable($identity);

                // A pending sign-up uses the IP's sign-up budget the moment it exists, counted
                // atomically (increment, then compare), so sign-ups prepared in many sessions
                // cannot all pass one check and be completed in parallel.
                if (! $this->countSignUp($request)) {
                    return $this->fail(false, self::TOO_MANY_SIGN_UPS);
                }

                // The account is only made once the visitor accepts the Terms of Service and
                // Privacy Policy on the consent screen, which posts to register() below. A new
                // session ID first: a session an attacker fixed must not hold the right to
                // create, and sign in to, this account.
                $request->session()->regenerate(true);
                OAuthRegistration::stash($request->session(), $identity);

                return new RedirectResponse(self::CONSENT_PATH);
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
     * Creates the account of a Discord/Google sign-up waiting on the consent screen, once the
     * visitor accepts the Terms of Service and Privacy Policy, and signs it in.
     *
     * @throws ValidationException
     */
    public function register(Request $request, CompletesLogins $logins): JsonResponse
    {
        $request->validate(
            [
                'terms' => ['accepted'],
                'nonce' => ['required', 'string', 'max:64'],
                'terms_version' => ['required', 'integer', 'min:1'],
                'privacy_version' => ['required', 'integer', 'min:1'],
            ],
            ['terms.accepted' => 'You must agree to the Terms of Service and Privacy Policy to create an account.'],
        );

        // Only the sign-up the visitor was shown: another one started in a second tab replaced
        // the nonce, and an expired one has none.
        $identity = OAuthRegistration::claim($request->session(), $request->string('nonce')->toString());
        if (! $identity instanceof OAuthIdentity || ! $identity->provider->enabled()) {
            throw ValidationException::withMessages(['terms' => self::SIGN_UP_EXPIRED]);
        }

        // And only the documents the visitor was shown.
        LegalSettings::ensureShownVersions($request->integer('terms_version'), $request->integer('privacy_version'));

        // The sign-up budget was already used when this sign-up was stashed (callback()).
        try {
            $user = $this->accounts->register($identity);
        } catch (OAuthException $oAuthException) {
            OAuthRegistration::forget($request->session());

            throw ValidationException::withMessages(['terms' => $oAuthException->getMessage()]);
        }

        OAuthRegistration::forget($request->session());
        LegalSettings::recordAcceptance($user);

        return new JsonResponse(['data' => $logins->complete($user)->toResponseData()], JsonResponse::HTTP_CREATED);
    }

    /**
     * Cancels the Discord/Google sign-up waiting on the consent screen ("Cancel" there).
     */
    public function cancelRegistration(Request $request): Response
    {
        OAuthRegistration::forget($request->session());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function registrationLimited(Request $request): bool
    {
        $limit = $this->registrationLimit($request);

        return RateLimiter::tooManyAttempts($limit['key'], $limit['max']);
    }

    /**
     * Uses one sign-up of the IP's budget: the counter is incremented first and compared after,
     * so concurrent requests cannot all slip under the limit. False when the budget is spent.
     */
    private function countSignUp(Request $request): bool
    {
        $limit = $this->registrationLimit($request);

        return RateLimiter::increment($limit['key'], $limit['decay']) <= $limit['max'];
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

        // Same key Laravel's ThrottleRequests builds for the named "registration" limiter, so the
        // sign-up form and provider sign-ups share one budget (covered by OAuthControllerTest).
        return ['key' => hash('md5', 'registration'.'ip:'.$request->ip()), 'max' => $limit->maxAttempts, 'decay' => $limit->decaySeconds];
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
