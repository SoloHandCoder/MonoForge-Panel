<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Share;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Pterodactyl\Models\Model;

/**
 * A counted vote, kept only while it still blocks the next one. Both keys are HMACs with the
 * app key and the page: neither the visitor's address nor their cookie is stored.
 *
 * @property int $id
 * @property int $server_page_id
 * @property string $address_hash
 * @property string $cookie_hash
 * @property string $network_hash
 * @property CarbonImmutable $voted_at
 */
#[Fillable(['server_page_id', 'address_hash', 'cookie_hash', 'network_hash', 'voted_at'])]
#[RouteKey('id')]
#[WithoutTimestamps]
class ServerPageVote extends Model
{
    use MassPrunable;

    /** Votes a page takes in a day from one /24 (IPv4) or /48 (IPv6). */
    public const int NETWORK_DAILY_VOTES = 5;

    /** A visitor votes once per this many hours on a page. */
    public const int COOLDOWN_HOURS = 24;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'server_page_id' => 'int',
            'voted_at' => 'immutable_datetime',
        ];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('voted_at', '<', CarbonImmutable::now()->subHours(self::COOLDOWN_HOURS));
    }
}
