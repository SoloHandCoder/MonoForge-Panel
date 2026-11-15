<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Auth\OAuth;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Pterodactyl\Enum\OAuthProvider;

/**
 * A Discord or Google sign-up waiting in the session for the visitor to accept the Terms of
 * Service and Privacy Policy. The provider callback keeps the identity here instead of creating
 * the account; the consent screen then creates it (POST /auth/modules/register).
 */
final class OAuthRegistration
{
    public const string SESSION_KEY = 'oauth_registration';

    /** How long the visitor has to accept before they must start again. */
    public const int TTL_SECONDS = 900;

    public static function stash(Session $session, OAuthIdentity $identity): void
    {
        $session->put(self::SESSION_KEY, [
            'provider' => $identity->provider->value,
            'id' => $identity->id,
            'username' => $identity->username,
            'display_name' => $identity->displayName,
            'email' => $identity->email,
            'avatar' => $identity->avatar,
            'expires_at' => CarbonImmutable::now()->getTimestamp() + self::TTL_SECONDS,
        ]);
    }

    /** The waiting identity, or null when there is none or it expired. */
    public static function peek(Session $session): ?OAuthIdentity
    {
        $stored = $session->get(self::SESSION_KEY);
        if (! is_array($stored)) {
            return null;
        }

        $expires = $stored['expires_at'] ?? 0;
        $provider = OAuthProvider::tryFrom(is_string($stored['provider'] ?? null) ? $stored['provider'] : '');
        $id = $stored['id'] ?? null;
        if (! is_int($expires) || $expires < CarbonImmutable::now()->getTimestamp() || ! $provider instanceof OAuthProvider || ! is_string($id) || $id === '') {
            return null;
        }

        $text = fn (string $key): ?string => is_string($stored[$key] ?? null) ? $stored[$key] : null;

        return new OAuthIdentity($provider, $id, $text('username'), $text('display_name'), $text('email'), $text('avatar'));
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}
