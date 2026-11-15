<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Pterodactyl\Enum\ShopMultiplierScope;
use Pterodactyl\Models\Model;

/**
 * Scales plan prices on a location or a node, such as 1.5x on a faster machine.
 *
 * @property int $id
 * @property ShopMultiplierScope $scope
 * @property int $scope_id
 * @property string $multiplier decimal(5,2); read it with value()
 * @property string|null $label
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['scope', 'scope_id', 'multiplier', 'label'])]
#[RouteKey('id')]
#[Table(name: 'shop_multipliers')]
class ShopMultiplier extends Model
{
    public const string RESOURCE_NAME = 'shop_multiplier';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => ShopMultiplierScope::class,
            'scope_id' => 'integer',
            'multiplier' => 'decimal:2',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function value(): float
    {
        return (float) $this->multiplier;
    }
}
