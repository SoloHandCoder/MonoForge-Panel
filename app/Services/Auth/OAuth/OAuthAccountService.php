<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Auth\OAuth;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Contracts\Users\CreatesUsers;
use Pterodactyl\Enum\OAuthProvider;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserOAuthAccount;
use Pterodactyl\Rules\Username;

/**
 * Links provider identities to panel users and creates accounts from them. Accounts are
 * only ever matched by the provider's user id, never by email address.
 */
final readonly class OAuthAccountService
{
    /** Names a new account may not take as they are; they get a numeric suffix instead. */
    private const array RESERVED_USERNAMES = ['admin', 'administrator', 'support', 'staff', 'monoforge', 'root', 'system', 'moderator'];

    public function __construct(private CreatesUsers $users) {}

    public function find(OAuthIdentity $identity): ?UserOAuthAccount
    {
        return UserOAuthAccount::query()
            ->where('provider', $identity->provider->value)
            ->where('provider_user_id', $identity->id)
            ->first();
    }

    /** Keep the shown name, email and avatar current after a sign in. */
    public function refresh(UserOAuthAccount $account, OAuthIdentity $identity): void
    {
        $account->fill($this->snapshot($identity))->save();
    }

    /**
     * Link the identity to a signed-in user.
     *
     * @throws OAuthException
     */
    public function link(User $user, OAuthIdentity $identity): UserOAuthAccount
    {
        $label = $identity->provider->label();
        $existing = $this->find($identity);

        if ($existing instanceof UserOAuthAccount) {
            throw_if($existing->user_id !== $user->id, OAuthException::class, "This {$label} account is already linked to another MonoForge account.");

            $this->refresh($existing, $identity);

            return $existing;
        }

        throw_if(
            UserOAuthAccount::query()->where('user_id', $user->id)->where('provider', $identity->provider->value)->exists(),
            OAuthException::class,
            "Your account already has a {$label} account linked. Unlink it first to link a different one.",
        );

        try {
            $account = UserOAuthAccount::query()->create([
                'user_id' => $user->id,
                'provider' => $identity->provider,
                'provider_user_id' => $identity->id,
                ...$this->snapshot($identity),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new OAuthException("This {$label} account is already linked to another MonoForge account.");
        }

        Activity::event('user:account.oauth-linked')->subject($user)->property('provider', $identity->provider->value)->log();

        return $account;
    }

    /**
     * Create a new panel account for an identity nobody has linked, then link it. The account
     * gets a random password and the welcome email with a link to set one.
     *
     * @throws OAuthException
     */
    public function register(OAuthIdentity $identity): User
    {
        $label = $identity->provider->label();

        throw_if($identity->email === null, OAuthException::class, "Your {$label} account has no verified email address, so a MonoForge account cannot be created from it.");
        throw_if(
            User::query()->where('email', $identity->email)->exists(),
            OAuthException::class,
            "A MonoForge account already uses this email address. Sign in with your password, then link {$label} from your account page.",
        );

        [$first, $last] = $this->names($identity);

        try {
            // The welcome email is queued after commit, so a rolled back registration sends nothing.
            return DB::transaction(function () use ($identity, $first, $last): User {
                $user = $this->users->create([
                    'email' => $identity->email,
                    'username' => $this->username($identity),
                    'name_first' => $first,
                    'name_last' => $last,
                ]);

                $user->forceFill(['has_password' => false])->save();
                $this->link($user, $identity);

                return $user;
            });
        } catch (ValidationException|UniqueConstraintViolationException) {
            // The provider's email or name failed the account rules, or another sign up took the
            // same email or username at the same moment. Never shown in detail.
            throw new OAuthException("A MonoForge account could not be created from your {$label} account. Please try again or contact support.");
        }
    }

    /**
     * Whether unlinking this account would leave the user with no way to sign in.
     */
    public function isLastLogin(User $user, UserOAuthAccount $account): bool
    {
        if ($this->hasPassword($user)) {
            return false;
        }

        return ! UserOAuthAccount::query()->where('user_id', $user->id)->whereKeyNot($account->id)->exists();
    }

    public function hasPassword(User $user): bool
    {
        $attributes = $user->getAttributes();
        $value = array_key_exists('has_password', $attributes)
            ? $attributes['has_password']
            : User::query()->whereKey($user->id)->value('has_password');

        return $value === null || (bool) $value;
    }

    /**
     * @return array{provider_username: string|null, provider_email: string|null, provider_avatar: string|null}
     */
    private function snapshot(OAuthIdentity $identity): array
    {
        return [
            'provider_username' => $identity->username !== null ? Str::limit($identity->username, 191, '') : null,
            'provider_email' => $identity->email !== null ? Str::limit($identity->email, 191, '') : null,
            'provider_avatar' => $identity->avatar !== null ? Str::limit($identity->avatar, 250, '') : null,
        ];
    }

    /** A valid, unused, unreserved username based on the provider's name. */
    private function username(OAuthIdentity $identity): string
    {
        $base = mb_strtolower($identity->username ?? $identity->displayName ?? '');
        $base = (string) preg_replace('/[^a-z0-9_.-]+/', '', $base);
        $base = mb_substr((string) preg_replace('/^[^a-z0-9]+|[^a-z0-9]+$/', '', $base), 0, 24);
        $base = (string) preg_replace('/[^a-z0-9]+$/', '', $base);

        if (mb_strlen($base) < 3) {
            $base = $identity->provider === OAuthProvider::Discord ? 'discord-user' : 'google-user';
        }

        $candidate = in_array($base, self::RESERVED_USERNAMES, true) ? $base.random_int(1000, 99999) : $base;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            if ($this->usernameAvailable($candidate)) {
                return $candidate;
            }

            $candidate = $base.random_int(1000, 99999);
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $base.'-'.mb_strtolower(Str::random(8));
            if ($this->usernameAvailable($candidate)) {
                return $candidate;
            }
        }

        throw new OAuthException('A username could not be chosen for your new account. Please try again.');
    }

    private function usernameAvailable(string $candidate): bool
    {
        return preg_match(Username::VALIDATION_REGEX, $candidate) === 1
            && ! in_array($candidate, self::RESERVED_USERNAMES, true)
            && ! User::query()->where('username', $candidate)->exists();
    }

    /**
     * @return array{string, string}
     */
    private function names(OAuthIdentity $identity): array
    {
        $display = mb_trim($identity->displayName ?? $identity->username ?? $identity->provider->label().' user');
        $parts = preg_split('/\s+/', $display, 2) ?: [$display];
        $first = Str::limit($parts[0], 191, '');
        $last = isset($parts[1]) && mb_trim($parts[1]) !== '' ? Str::limit(mb_trim($parts[1]), 191, '') : $first;

        return [$first, $last];
    }
}
