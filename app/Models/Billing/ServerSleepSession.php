<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\Server;

/**
 * One stretch of time auto-sleep kept a server stopped: from the graceful stop until somebody
 * started it again (`ended_at` is null while it still sleeps). A renewal sums the part of every
 * session that falls inside the period it bills, at the discount recorded when it started.
 *
 * @property int $id
 * @property int $server_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property int $discount_percent the plan's sleep discount when the sleep started (locked in)
 * @property-read Server $server
 */
#[Fillable(['server_id', 'started_at', 'ended_at', 'discount_percent'])]
#[RouteKey('id')]
#[Table(name: 'server_sleep_sessions')]
#[WithoutTimestamps]
class ServerSleepSession extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'server_id' => 'int',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'discount_percent' => 'int',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
