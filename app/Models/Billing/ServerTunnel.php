<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\Server;

/**
 * The playit.gg tunnel that gives a server its public address, such as `xyz.joinmc.link`.
 *
 * @property int $id
 * @property int $server_id
 * @property int|null $allocation_id
 * @property string|null $playit_tunnel_id
 * @property string|null $address
 * @property string|null $subdomain
 * @property TunnelStatus $status
 * @property string|null $last_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Server|null $server
 * @property-read Allocation|null $allocation
 */
#[Fillable([
    'server_id',
    'allocation_id',
    'playit_tunnel_id',
    'address',
    'subdomain',
    'status',
    'last_error',
])]
#[RouteKey('id')]
class ServerTunnel extends Model
{
    public const string RESOURCE_NAME = 'server_tunnel';

    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'server_id' => 'int',
            'allocation_id' => 'int',
            'status' => TunnelStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<Allocation, $this>
     */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class);
    }
}
