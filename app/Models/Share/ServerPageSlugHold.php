<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Share;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Pterodactyl\Models\Model;

/**
 * A slug a page gave up when it was renamed. For HOLD_DAYS only the same server can take it back,
 * so links already shared never lead to somebody else's page.
 *
 * @property int $id
 * @property string $slug
 * @property int $server_id
 * @property CarbonImmutable $expires_at
 */
#[Fillable(['slug', 'server_id', 'expires_at'])]
#[Table(name: 'server_page_slug_holds')]
#[WithoutTimestamps]
class ServerPageSlugHold extends Model
{
    use MassPrunable;

    public const int HOLD_DAYS = 30;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'server_id' => 'int',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** Whether another server holds the slug right now. */
    public static function heldForOther(string $slug, ?int $serverId): bool
    {
        return static::query()
            ->where('slug', $slug)
            ->where('expires_at', '>', CarbonImmutable::now())
            ->when($serverId !== null, fn (Builder $query): Builder => $query->where('server_id', '!=', $serverId))
            ->exists();
    }

    /** Holds the old slug of a renamed page for its server, and frees the new one if it held it. */
    public static function rename(int $serverId, string $old, string $new): void
    {
        static::query()->where('slug', $new)->where('server_id', $serverId)->delete();
        static::query()->updateOrCreate(['slug' => $old], ['server_id' => $serverId, 'expires_at' => CarbonImmutable::now()->addDays(self::HOLD_DAYS)]);
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', CarbonImmutable::now());
    }
}
