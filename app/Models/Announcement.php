<?php

declare(strict_types=1);

namespace Pterodactyl\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Enum\NoticeLevel;

/**
 * A panel-wide message an administrator shows to everyone, such as planned downtime.
 *
 * @property int $id
 * @property string $title
 * @property string $body
 * @property NoticeLevel $level
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property bool $dismissible
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'title',
    'body',
    'level',
    'starts_at',
    'ends_at',
    'dismissible',
])]
#[RouteKey('id')]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    public const string RESOURCE_NAME = 'announcement';

    /**
     * The announcements that have not ended, which every open tab polls for. Which of them
     * are showing is decided when they are read, so the entry only changes on a write.
     */
    public const string CACHE_KEY = 'announcements:unended';

    /** Bounds how long a write that bypasses the model goes unseen; model writes clear it at once. */
    public const int CACHE_SECONDS = 60;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => NoticeLevel::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'dismissible' => 'boolean',
        ];
    }

    /**
     * The announcements showing now, newest first: what `active()` selects, read through the cache store.
     *
     * @return Collection<int, self>
     */
    public static function showing(): Collection
    {
        $unended = rescue(fn (): mixed => Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): Collection => self::unended()), report: false);
        if (! $unended instanceof Collection) {
            $unended = self::unended();
        }

        return $unended->filter(fn (self $announcement): bool => $announcement->status() === 'active')->values();
    }

    public static function forgetCache(): bool
    {
        return (bool) rescue(fn (): bool => Cache::forget(self::CACHE_KEY), false, report: false);
    }

    /** Whether the announcement is showing now: `active`, `scheduled` before it starts or `ended` after it ends. */
    public function status(): string
    {
        $now = CarbonImmutable::now();

        if ($this->starts_at !== null && $this->starts_at->greaterThan($now)) {
            return 'scheduled';
        }

        if ($this->ends_at !== null && $this->ends_at->lessThanOrEqualTo($now)) {
            return 'ended';
        }

        return 'active';
    }

    protected static function booted(): void
    {
        static::saved(fn (): bool => self::forgetCache());
        static::deleted(fn (): bool => self::forgetCache());
    }

    /**
     * Announcements whose schedule includes the current moment; an unset start or end is open.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $now = CarbonImmutable::now();

        $query
            ->where(fn (Builder $starts) => $starts->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $ends) => $ends->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    /**
     * @return Collection<int, self>
     */
    private static function unended(): Collection
    {
        $now = CarbonImmutable::now();

        return self::query()
            ->where(fn (Builder $ends) => $ends->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->latest('id')
            ->get();
    }
}
