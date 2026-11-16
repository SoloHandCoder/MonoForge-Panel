<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Enum\PlayitClaimStatus;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\Node;

/**
 * A node's "Set up automatic tunnels" claim on playit.gg and the self-managed agent it produced.
 *
 * The agent secret is stored encrypted and never serialised; the switch script's one-time token
 * is only kept as its SHA-256.
 *
 * @property int $id
 * @property int $node_id
 * @property string $code
 * @property PlayitClaimStatus $status
 * @property string|null $agent_id
 * @property string|null $secret
 * @property string|null $token_hash
 * @property CarbonImmutable|null $token_expires_at
 * @property CarbonImmutable|null $fetched_at
 * @property string|null $report
 * @property CarbonImmutable|null $reported_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Node $node
 */
#[Fillable([
    'node_id',
    'code',
    'status',
    'agent_id',
    'secret',
    'token_hash',
    'token_expires_at',
    'fetched_at',
    'report',
    'reported_at',
    'last_error',
    'expires_at',
])]
#[Hidden(['code', 'secret', 'token_hash'])]
class PlayitAgentClaim extends Model
{
    public const string RESOURCE_NAME = 'playit_agent_claim';

    /** What the switch script reported. */
    public const string REPORT_SWITCHED = 'switched';

    public const string REPORT_ROLLED_BACK = 'rolled_back';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'node_id' => 'int',
            'status' => PlayitClaimStatus::class,
            'secret' => 'encrypted',
            'token_expires_at' => 'immutable_datetime',
            'fetched_at' => 'immutable_datetime',
            'reported_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
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
}
