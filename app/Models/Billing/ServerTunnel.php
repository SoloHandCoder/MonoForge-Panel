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
 * @property string $port_key
 * @property string|null $label
 * @property string|null $protocol
 * @property string|null $hold_reason
 * @property CarbonImmutable|null $retry_after
 * @property int|null $allocation_id
 * @property string|null $playit_tunnel_id
 * @property string|null $address
 * @property string|null $subdomain
 * @property TunnelStatus $status
 * @property string|null $last_error
 * @property CarbonImmutable|null $imported_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Server|null $server
 * @property-read Allocation|null $allocation
 */
#[Fillable([
    'server_id',
    'port_key',
    'label',
    'protocol',
    'allocation_id',
    'playit_tunnel_id',
    'address',
    'subdomain',
    'status',
    'last_error',
    'imported_at',
    'hold_reason',
    'retry_after',
])]
#[RouteKey('id')]
class ServerTunnel extends Model
{
    public const string RESOURCE_NAME = 'server_tunnel';

    /** The port key of a server's main tunnel, to its primary allocation. */
    public const string MAIN = 'game';

    /** The server's egg belongs to no game that asks for tunnels; the scheduler leaves it. */
    public const string HOLD_NOT_A_GAME = 'not_a_game';

    /** The playit account has no room for another tunnel; retried after a long pause. */
    public const string HOLD_TUNNEL_LIMIT = 'tunnel_limit';

    protected $attributes = [
        'status' => 'pending',
        'port_key' => self::MAIN,
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
            'imported_at' => 'immutable_datetime',
            'retry_after' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Whether the playit tunnel was made by hand and linked by "Import existing tunnels". The
     * panel leaves such a tunnel alone on playit unless it is deleted on purpose.
     */
    public function isMain(): bool
    {
        return $this->port_key === self::MAIN;
    }

    public function isImported(): bool
    {
        return $this->imported_at !== null;
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
