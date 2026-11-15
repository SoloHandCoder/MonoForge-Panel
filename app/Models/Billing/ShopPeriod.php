<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Pterodactyl\Models\Model;

/**
 * A billing period a player can pick, such as 30, 90 or 365 days, with an optional
 * discount (negative percent) or surcharge (positive percent).
 *
 * @property int $id
 * @property string $name
 * @property int $days
 * @property int $price_modifier_percent
 * @property int $sort
 * @property bool $enabled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'days', 'price_modifier_percent', 'sort', 'enabled'])]
#[RouteKey('id')]
#[Table(name: 'shop_periods')]
class ShopPeriod extends Model
{
    public const string RESOURCE_NAME = 'shop_period';

    protected $attributes = [
        'price_modifier_percent' => 0,
        'sort' => 0,
        'enabled' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'price_modifier_percent' => 'integer',
            'sort' => 'integer',
            'enabled' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** What a 30-day price is multiplied by for this period, modifier included. */
    public function factor(): float
    {
        return $this->days / 30 * (1 + $this->price_modifier_percent / 100);
    }

    /**
     * Periods in the admin's order, shortest first on a tie.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('days')->orderBy('id');
    }
}
