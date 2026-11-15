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
 * One reachability check of the panel or of a node, taken every minute for the public status page.
 *
 * @property int $id
 * @property string $target "panel" or "node:{id}"
 * @property bool $ok
 * @property int|null $response_ms
 * @property CarbonImmutable $checked_at
 */
#[Fillable(['target', 'ok', 'response_ms', 'checked_at'])]
#[RouteKey('id')]
#[WithoutTimestamps]
class StatusCheck extends Model
{
    use MassPrunable;

    /** Checks and their daily sums are kept this long: the status page shows 90 days. */
    public const int RETENTION_DAYS = 90;

    public const string PANEL = 'panel';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'response_ms' => 'integer',
            'checked_at' => 'immutable_datetime',
        ];
    }

    public static function nodeTarget(int $id): string
    {
        return 'node:'.$id;
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('checked_at', '<', CarbonImmutable::now()->subDays(self::RETENTION_DAYS));
    }
}
