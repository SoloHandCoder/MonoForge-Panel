<?php

declare(strict_types=1);

namespace Pterodactyl\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
}
