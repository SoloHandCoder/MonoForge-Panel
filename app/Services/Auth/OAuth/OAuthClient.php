<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Auth\OAuth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Pterodactyl\Enum\OAuthProvider;
use Pterodactyl\Exceptions\Service\Auth\OAuthException;

/**
 * The OAuth2 authorization-code flow for Discord and Google, with Laravel's HTTP client.
 * The state (and Google's PKCE verifier) lives in the session and is used once.
 */
final readonly class OAuthClient
{
    private const string SESSION_KEY = 'oauth_pending';

    private const int LIFETIME_SECONDS = 600;

    /**
     * Start an authorization request and return the provider URL to send the browser to.
     * Starting another request replaces the one waiting in the session.
     */
    public function begin(OAuthProvider $provider, string $intent, ?int $userId = null): string
    {
        $state = Str::random(40);
        $verifier = $provider->usesPkce() ? Str::random(64) : null;

        Session::put(self::SESSION_KEY, new OAuthPending(
            $provider,
            $state,
            $verifier,
            $intent,
            $userId,
            now()->getTimestamp() + self::LIFETIME_SECONDS,
        ));

        $query = [
            'client_id' => $provider->clientId(),
            'redirect_uri' => $provider->callbackUrl(),
            'response_type' => 'code',
            'scope' => $provider->scope(),
            'state' => $state,
        ];

        if ($verifier !== null) {
            $query['code_challenge'] = mb_rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $query['code_challenge_method'] = 'S256';
            $query['prompt'] = 'select_account';
        }

        return $provider->authorizeUrl().'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Return the waiting request only when it was issued for this provider, its state matches
     * the one sent back and it is unexpired. It is used up only when the state matches, so a
     * forged callback cannot cancel a sign in that is still in progress.
     */
    public function pull(OAuthProvider $provider, mixed $state): ?OAuthPending
    {
        $pending = Session::get(self::SESSION_KEY);

        if (! $pending instanceof OAuthPending || ! is_string($state) || $state === '') {
            return null;
        }

        if ($pending->provider !== $provider || ! hash_equals($pending->state, $state)) {
            return null;
        }

        Session::forget(self::SESSION_KEY);

        return $pending->expiresAt < now()->getTimestamp() ? null : $pending;
    }

    /**
     * Exchange the authorization code and read the identity behind it.
     *
     * @throws OAuthException
     */
    public function identity(OAuthPending $pending, string $code): OAuthIdentity
    {
        $provider = $pending->provider;
        $form = [
            'client_id' => $provider->clientId(),
            'client_secret' => $provider->clientSecret(),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $provider->callbackUrl(),
        ];

        if ($pending->verifier !== null) {
            $form['code_verifier'] = $pending->verifier;
        }

        $token = $this->send($provider, 'token', fn (): Response => $this->http()->asForm()->post($provider->tokenUrl(), $form));
        $accessToken = $token->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            throw $this->failure($provider, 'token', $token->status());
        }

        $profile = $this->send($provider, 'identity', fn (): Response => $this->http()->withToken($accessToken)->get($provider->identityUrl()));

        return match ($provider) {
            OAuthProvider::Discord => $this->discordIdentity($profile),
            OAuthProvider::Google => $this->googleIdentity($profile),
        };
    }

    private function discordIdentity(Response $profile): OAuthIdentity
    {
        $id = $this->string($profile, 'id');
        if ($id === null || preg_match('/^\d{1,32}$/', $id) !== 1) {
            throw $this->failure(OAuthProvider::Discord, 'identity', $profile->status());
        }

        $avatar = $this->string($profile, 'avatar');

        return new OAuthIdentity(
            provider: OAuthProvider::Discord,
            id: $id,
            username: $this->string($profile, 'username'),
            displayName: $this->string($profile, 'global_name') ?? $this->string($profile, 'username'),
            email: $profile->json('verified') === true ? $this->email($profile, 'email') : null,
            avatar: $avatar !== null && preg_match('/^[a-zA-Z0-9_]+$/', $avatar) === 1
                ? "https://cdn.discordapp.com/avatars/{$id}/{$avatar}.png"
                : null,
        );
    }

    private function googleIdentity(Response $profile): OAuthIdentity
    {
        $id = $this->string($profile, 'sub');
        if ($id === null) {
            throw $this->failure(OAuthProvider::Google, 'identity', $profile->status());
        }

        $email = $profile->json('email_verified') === true ? $this->email($profile, 'email') : null;
        $picture = $this->string($profile, 'picture');

        return new OAuthIdentity(
            provider: OAuthProvider::Google,
            id: $id,
            username: $email !== null ? Str::before($email, '@') : $this->string($profile, 'given_name'),
            displayName: $this->string($profile, 'name'),
            email: $email,
            avatar: $picture !== null && str_starts_with($picture, 'https://') ? Str::limit($picture, 250, '') : null,
        );
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(10)->connectTimeout(5);
    }

    /**
     * @param  callable(): Response  $request
     *
     * @throws OAuthException
     */
    private function send(OAuthProvider $provider, string $step, callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw $this->failure($provider, $step, null);
        }

        if (! $response->successful()) {
            throw $this->failure($provider, $step, $response->status());
        }

        return $response;
    }

    /** Logs the step and status only: never the code, tokens or response body. */
    private function failure(OAuthProvider $provider, string $step, ?int $status): OAuthException
    {
        Log::warning('OAuth login request failed', ['provider' => $provider->value, 'step' => $step, 'status' => $status]);

        return new OAuthException("We could not reach {$provider->label()} to finish signing in. Please try again.");
    }

    private function string(Response $response, string $key): ?string
    {
        $value = $response->json($key);
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }

    private function email(Response $response, string $key): ?string
    {
        $value = $this->string($response, $key);

        return $value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($value) : null;
    }
}
