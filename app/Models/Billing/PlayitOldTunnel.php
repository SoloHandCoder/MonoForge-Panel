<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

/**
 * A tunnel on a node's previous playit.gg agent, recorded when the node switched to a
 * self-managed agent. It no longer carries traffic; "Remove old tunnels" deletes it on playit
 * (or the administrator does so in the dashboard when the old key is read-only), and undoing the
 * switch links it to its server again.
 *
 * @property int $id
 * @property int $node_id
 * @property int|null $server_id
 * @property string|null $agent_id
 * @property string $playit_tunnel_id
 * @property string|null $name
 * @property string|null $address
 * @property int|null $local_port
 * @property CarbonImmutable|null $removed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Node $node
 * @property-read Server|null $server
 */
#[Fillable([
    'node_id',
    'server_id',
    'agent_id',
    'playit_tunnel_id',
    'name',
    'address',
    'local_port',
    'removed_at',
])]
class PlayitOldTunnel extends Model
{
    public const string RESOURCE_NAME = 'playit_old_tunnel';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'node_id' => 'int',
            'server_id' => 'int',
            'local_port' => 'int',
            'removed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
