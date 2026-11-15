<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Pterodactyl\Enum\ShopAddonResource;
use Pterodactyl\Models\Model;

/**
 * An optional extra a player can add at checkout, such as "+1 backup", priced per unit
 * per 30 days.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property ShopAddonResource $resource
 * @property int $amount
 * @property int $price_tokens
 * @property int $max_units
 * @property bool $enabled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['key', 'name', 'description', 'resource', 'amount', 'price_tokens', 'max_units', 'enabled'])]
#[RouteKey('id')]
#[Table(name: 'shop_addons')]
class ShopAddon extends Model
{
    public const string RESOURCE_NAME = 'shop_addon';

    protected $attributes = [
        'max_units' => 1,
        'enabled' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resource' => ShopAddonResource::class,
            'amount' => 'integer',
            'price_tokens' => 'integer',
            'max_units' => 'integer',
            'enabled' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
