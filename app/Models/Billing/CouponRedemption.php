<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * One use of a coupon, kept as its audit trail. The database allows one per coupon and user.
 *
 * @property int $id
 * @property int $coupon_id
 * @property int|null $user_id
 * @property int $tokens the tokens off the first period, or the tokens credited
 * @property string|null $purchase_reference the store purchase it discounted (its ledger reference)
 * @property int|null $server_id
 * @property string|null $network_hash
 * @property CarbonImmutable $created_at
 * @property Coupon $coupon
 * @property User|null $user
 * @property Server|null $server
 */
#[Fillable(['coupon_id', 'user_id', 'tokens', 'purchase_reference', 'server_id', 'network_hash'])]
#[RouteKey('id')]
#[Table(name: 'coupon_redemptions')]
class CouponRedemption extends Model
{
    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coupon_id' => 'integer',
            'user_id' => 'integer',
            'tokens' => 'integer',
            'server_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'coupon' => ['id' => $this->coupon->id, 'code' => $this->coupon->code, 'type' => $this->coupon->type],
            'user' => $this->user instanceof User ? ['id' => $this->user->id, 'username' => $this->user->username, 'email' => $this->user->email] : null,
            'tokens' => $this->tokens,
            'server' => $this->server instanceof Server ? ['id' => $this->server->id, 'uuid' => $this->server->uuid, 'name' => $this->server->name] : null,
            'created_at' => $this->created_at->toAtomString(),
        ];
    }
}
