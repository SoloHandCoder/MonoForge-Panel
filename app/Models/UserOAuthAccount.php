<?php

declare(strict_types=1);

namespace Pterodactyl\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Enum\OAuthProvider;

/**
 * A Discord or Google account linked to a panel user, which can sign that user in.
 *
 * @property int $id
 * @property int $user_id
 * @property OAuthProvider $provider
 * @property string $provider_user_id
 * @property string|null $provider_username
 * @property string|null $provider_email
 * @property string|null $provider_avatar
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'provider',
    'provider_user_id',
    'provider_username',
    'provider_email',
    'provider_avatar',
])]
#[RouteKey('id')]
#[Table(name: 'user_oauth_accounts')]
class UserOAuthAccount extends Model
{
    public const string RESOURCE_NAME = 'user_oauth_account';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'int',
            'provider' => OAuthProvider::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
