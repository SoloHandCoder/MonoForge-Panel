<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Migration;

use Illuminate\Database\Eloquent\Builder;
use Pterodactyl\Enum\OAuthProvider;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserOAuthAccount;

/**
 * Copies rows of the old m12labs `user_oauth_accounts` table into MonoForge. User IDs are the
 * same in both panels because the core import kept them.
 */
final class M12LabsOAuthLinkImporter
{
    public const array OUTCOMES = ['imported', 'already linked', 'missing user', 'conflict', 'invalid'];

    /**
     * @param  iterable<array<array-key, mixed>|object>  $rows
     * @return array<string, int> the number of rows per outcome
     */
    public function import(iterable $rows, bool $dryRun): array
    {
        $counts = array_fill_keys(self::OUTCOMES, 0);
        foreach ($rows as $row) {
            $counts[$this->importRow((array) $row, $dryRun)]++;
        }

        return $counts;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return 'imported'|'already linked'|'missing user'|'conflict'|'invalid'
     */
    public function importRow(array $row, bool $dryRun): string
    {
        $provider = OAuthProvider::tryFrom(is_string($row['provider'] ?? null) ? $row['provider'] : '');
        $userId = filter_var($row['user_id'] ?? null, FILTER_VALIDATE_INT);
        $providerUserId = $row['provider_user_id'] ?? null;
        if (! $provider instanceof OAuthProvider || $userId === false || ! is_scalar($providerUserId) || (string) $providerUserId === '') {
            return 'invalid';
        }

        $providerUserId = (string) $providerUserId;
        $existing = UserOAuthAccount::query()
            ->where('provider', $provider->value)
            ->where(fn (Builder $query): Builder => $query->where('provider_user_id', $providerUserId)->orWhere('user_id', $userId))
            ->get();

        if ($existing->contains(fn (UserOAuthAccount $account): bool => $account->user_id === $userId && $account->provider_user_id === $providerUserId)) {
            return 'already linked';
        }

        // The provider account is linked to someone else, or the user already has another one.
        if ($existing->isNotEmpty()) {
            return 'conflict';
        }

        if (! User::query()->whereKey($userId)->exists()) {
            return 'missing user';
        }

        if (! $dryRun) {
            UserOAuthAccount::query()->create([
                'user_id' => $userId,
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
                'provider_username' => $this->nullableString($row['provider_username'] ?? null, 191),
                'provider_email' => $this->nullableString($row['provider_email'] ?? null, 191),
                'provider_avatar' => $this->nullableString($row['provider_avatar'] ?? null, 255),
            ]);
        }

        return 'imported';
    }

    private function nullableString(mixed $value, int $limit): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $limit) : null;
    }
}
