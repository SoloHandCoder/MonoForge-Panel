<?php

declare(strict_types=1);

namespace Pterodactyl\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PermissionTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A preset of subuser permissions that pre-fills the subuser form. A null `user_id` is a global
 * template an admin made; otherwise it belongs to that user's account. A template is only ever
 * copied into the form, so editing or deleting it never changes an existing subuser.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property list<string> $permissions
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name',
    'permissions',
])]
#[RouteKey('id')]
class PermissionTemplate extends Model
{
    /** @use HasFactory<PermissionTemplateFactory> */
    use HasFactory;

    public const string RESOURCE_NAME = 'permission_template';

    /** How many templates one account may keep. */
    public const int MAX_PER_ACCOUNT = 25;

    /** How many global templates the panel keeps. */
    public const int MAX_GLOBAL = 50;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'int',
            'permissions' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function global(Builder $query): void
    {
        $query->whereNull('user_id');
    }
}
