<?php

declare(strict_types=1);

namespace Pterodactyl\Models;

use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model as IlluminateModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use LogicException;
use Pterodactyl\Contracts\Models\Identifiable;
use Pterodactyl\Events\ActivityLogged;
use Pterodactyl\Models\Builders\ActivityLogBuilder;
use Pterodactyl\Models\Traits\HasRealtimeIdentifier;
use Pterodactyl\Services\Activity\InfrastructureIps;

/**
 * @property Collection<string, mixed> $properties
 */
#[Attributes\Identifiable('actl')]
#[Guarded([
    'id',
    'timestamp',
])]
#[WithoutTimestamps]
#[UseEloquentBuilder(ActivityLogBuilder::class)]
class ActivityLog extends Model implements Identifiable
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;

    use HasRealtimeIdentifier;
    use MassPrunable;

    public const string RESOURCE_NAME = 'activity_log';

    /**
     * Tracks all the events we no longer wish to display to users. These are either legacy
     * events or just events where we never ended up using the associated data.
     */
    public const array DISABLED_EVENTS = ['server:file.upload'];

    protected $with = ['subjects'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'collection',
            'timestamp' => 'datetime',
        ];
    }

    /**
     * Whether an address is part of the panel's own infrastructure and so never shown.
     */
    public static function isInfrastructureIp(?string $ip): bool
    {
        return resolve(InfrastructureIps::class)->contains($ip);
    }

    /**
     * Whether the user is an administrator. Admin permissions are granted through the root
     * administrator flag only (see AuthServiceProvider and the Permissions enum).
     */
    public static function isAdministrator(User $user): bool
    {
        return (bool) $user->root_admin;
    }

    /**
     * @return MorphTo<IlluminateModel, $this>
     */
    public function actor(): MorphTo
    {
        $morph = $this->morphTo();
        if (method_exists($morph, 'withTrashed')) { // @phpstan-ignore function.alreadyNarrowedType
            return $morph->withTrashed();
        }

        return $morph;
    }

    /**
     * @return HasMany<ActivityLogSubject, $this>
     */
    public function subjects(): HasMany
    {
        return $this->hasMany(ActivityLogSubject::class);
    }

    /**
     * @return HasOne<ApiKey, $this>
     */
    public function apiKey(): HasOne
    {
        return $this->hasOne(ApiKey::class, 'id', 'api_key_id');
    }

    /**
     * The originating address as it may be shown to anyone. It is withheld when:
     * - it belongs to the panel host or a node (see InfrastructureIps): loopback, private,
     *   unique local and link-local ranges, the node FQDNs and the admin's hidden list;
     * - the actor is an administrator (root administrators are the panel's only admin role);
     * - no user actor performed the action, such as system, node and Wings triggered events.
     * Anonymous authentication attempts against an account keep a genuine external address,
     * as it belongs to whoever made the attempt.
     */
    public function visibleIp(): ?string
    {
        if (self::isInfrastructureIp($this->ip)) {
            return null;
        }

        // Entries without a user actor show as "System" (failed logins included). Their
        // addresses are never shown: they are often the panel host's own, changing, IPv6.
        $actor = $this->actor;
        if (! $actor instanceof User || self::isAdministrator($actor)) {
            return null;
        }

        return $this->ip;
    }

    /**
     * Whether this is an authentication attempt (failed login, password reset, ...)
     * that was made without any identified actor.
     */
    public function isAnonymousAuthAttempt(): bool
    {
        return $this->actor_id === null && str_starts_with($this->event, 'auth:');
    }

    /** @return array<string, mixed> */
    public function propertyValues(): array
    {
        return $this->properties->all();
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = config('activity.prune_days');
        throw_unless($days > 0, LogicException::class, 'Cannot prune activity logs: no "prune_days" configuration value is set.');

        return static::query()->where('timestamp', '<=', now()->subDays($days));
    }

    protected static function boot(): void
    {
        parent::boot();

        static::created(function (self $model): void {
            Event::dispatch(new ActivityLogged($model));
        });
    }
}
