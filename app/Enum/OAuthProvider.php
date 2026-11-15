<?php

declare(strict_types=1);

namespace Pterodactyl\Enum;

/**
 * An external identity provider users can sign in with and link to their account.
 */
enum OAuthProvider: string
{
    case Discord = 'discord';
    case Google = 'google';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $provider): string => $provider->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Discord => 'Discord',
            self::Google => 'Google',
        };
    }

    public function authorizeUrl(): string
    {
        return match ($this) {
            self::Discord => 'https://discord.com/oauth2/authorize',
            self::Google => 'https://accounts.google.com/o/oauth2/v2/auth',
        };
    }

    public function tokenUrl(): string
    {
        return match ($this) {
            self::Discord => 'https://discord.com/api/oauth2/token',
            self::Google => 'https://oauth2.googleapis.com/token',
        };
    }

    public function identityUrl(): string
    {
        return match ($this) {
            self::Discord => 'https://discord.com/api/users/@me',
            self::Google => 'https://openidconnect.googleapis.com/v1/userinfo',
        };
    }

    public function scope(): string
    {
        return match ($this) {
            self::Discord => 'identify email',
            self::Google => 'openid email profile',
        };
    }

    /** Google gets PKCE on top of the state parameter. */
    public function usesPkce(): bool
    {
        return $this === self::Google;
    }

    /** The prefix of this provider's settings, such as `monoforge:auth:discord`. */
    public function settingPrefix(): string
    {
        return 'monoforge:auth:'.$this->value;
    }

    public function enabled(): bool
    {
        return filter_var(config('monoforge.auth.'.$this->value.'.enabled'), FILTER_VALIDATE_BOOL)
            && $this->clientId() !== ''
            && $this->clientSecret() !== '';
    }

    public function clientId(): string
    {
        $value = config('monoforge.auth.'.$this->value.'.client_id');

        return is_string($value) ? mb_trim($value) : '';
    }

    public function clientSecret(): string
    {
        $value = config('monoforge.auth.'.$this->value.'.client_secret');

        return is_string($value) ? mb_trim($value) : '';
    }

    /**
     * The exact callback URL to register with the provider. Built from APP_URL, never from
     * the request's Host header.
     */
    public function callbackUrl(): string
    {
        return mb_rtrim(config()->string('app.url'), '/').'/auth/modules/'.$this->value.'/authenticate';
    }
}
