<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Status;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Pterodactyl\Models\Model;

/**
 * A day of status checks for one target, summed as they are taken so the 90-day bars read
 * one row per day.
 *
 * @property int $id
 * @property string $target "panel" or "node:{id}"
 * @property CarbonImmutable $date
 * @property int $checks
 * @property int $successes
 * @property int $response_ms_total
 * @property int $response_samples
 */
#[Fillable(['target', 'date', 'checks', 'successes', 'response_ms_total', 'response_samples'])]
#[RouteKey('id')]
#[WithoutTimestamps]
class StatusDay extends Model
{
    use MassPrunable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'checks' => 'integer',
            'successes' => 'integer',
            'response_ms_total' => 'integer',
            'response_samples' => 'integer',
        ];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('date', '<', CarbonImmutable::now()->subDays(StatusCheck::RETENTION_DAYS)->toDateString());
    }
}
