<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Auth\OAuth;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use Pterodactyl\Enum\OAuthProvider;

/**
 * A Discord or Google sign-up waiting in the session for the visitor to accept the Terms of
 * Service and Privacy Policy. The provider callback keeps the identity here instead of creating
 * the account; the consent screen then creates it (POST /auth/modules/register).
 *
 * Each stash carries a one-time nonce the consent screen shows and posts back, so the account
 * created is the one the visitor saw (a second sign-up in another tab replaces the nonce).
 */
final class OAuthRegistration
{
    public const string SESSION_KEY = 'oauth_registration';

    /** How long the visitor has to accept before they must start again. */
    public const int TTL_SECONDS = 600;

    public static function stash(Session $session, OAuthIdentity $identity): void
    {
        $session->put(self::SESSION_KEY, [
            'provider' => $identity->provider->value,
            'id' => $identity->id,
            'username' => $identity->username,
            'display_name' => $identity->displayName,
            'email' => $identity->email,
            'avatar' => $identity->avatar,
            'nonce' => Str::random(40),
            'expires_at' => CarbonImmutable::now()->getTimestamp() + self::TTL_SECONDS,
        ]);
    }

    /** The waiting identity, or null when there is none or it expired. */
    public static function peek(Session $session): ?OAuthIdentity
    {
        $stored = self::stored($session);
        if ($stored === null) {
            return null;
        }

        $text = fn (string $key): ?string => is_string($stored[$key] ?? null) ? $stored[$key] : null;

        return new OAuthIdentity(OAuthProvider::from($stored['provider']), $stored['id'], $text('username'), $text('display_name'), $text('email'), $text('avatar'));
    }

    /** The nonce of the waiting sign-up, which the consent screen must send back. */
    public static function nonce(Session $session): ?string
    {
        return self::stored($session)['nonce'] ?? null;
    }

    /** The waiting identity, only when the nonce is the one of the sign-up the visitor was shown. */
    public static function claim(Session $session, string $nonce): ?OAuthIdentity
    {
        $expected = self::nonce($session);

        return $expected !== null && $nonce !== '' && hash_equals($expected, $nonce) ? self::peek($session) : null;
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    /**
     * @return array{provider: string, id: string, nonce: string, username?: mixed, display_name?: mixed, email?: mixed, avatar?: mixed}|null
     */
    private static function stored(Session $session): ?array
    {
        $stored = $session->get(self::SESSION_KEY);
        if (! is_array($stored)) {
            return null;
        }

        $expires = $stored['expires_at'] ?? 0;
        $provider = $stored['provider'] ?? null;
        $id = $stored['id'] ?? null;
        $nonce = $stored['nonce'] ?? null;
        if (! is_int($expires) || $expires < CarbonImmutable::now()->getTimestamp()
            || ! is_string($provider) || ! OAuthProvider::tryFrom($provider) instanceof OAuthProvider
            || ! is_string($id) || $id === '' || ! is_string($nonce) || $nonce === '') {
            return null;
        }

        return [
            'provider' => $provider,
            'id' => $id,
            'nonce' => $nonce,
            'username' => $stored['username'] ?? null,
            'display_name' => $stored['display_name'] ?? null,
            'email' => $stored['email'] ?? null,
            'avatar' => $stored['avatar'] ?? null,
        ];
    }
}
