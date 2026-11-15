<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pterodactyl\Models\Model;

/**
 * A group of shop plans, such as "Minecraft" or "Bots".
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $sort
 * @property bool $visible
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, ShopPlan> $plans
 * @property-read int|null $plans_count
 */
#[Fillable(['name', 'slug', 'description', 'sort', 'visible'])]
#[RouteKey('id')]
#[Table(name: 'shop_categories')]
class ShopCategory extends Model
{
    public const string RESOURCE_NAME = 'shop_category';

    protected $attributes = [
        'sort' => 0,
        'visible' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'visible' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<ShopPlan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(ShopPlan::class, 'category_id');
    }

    /**
     * Categories in the order the admin arranged them.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }
}
