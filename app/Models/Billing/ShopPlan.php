<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Model;
use Pterodactyl\Models\Node;

/**
 * A server size sold in the shop for tokens per 30 days.
 *
 * @property int $id
 * @property int $category_id
 * @property string $name
 * @property string|null $description
 * @property bool $visible
 * @property int $sort
 * @property int $price_tokens
 * @property int $memory
 * @property int $swap
 * @property int $disk
 * @property int $cpu
 * @property int $io
 * @property int $databases
 * @property int $backups
 * @property int $allocations
 * @property array<int, int|string> $egg_ids
 * @property array<int, int|string>|null $node_ids
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ShopCategory $category
 */
#[Fillable([
    'category_id',
    'name',
    'description',
    'visible',
    'sort',
    'price_tokens',
    'memory',
    'swap',
    'disk',
    'cpu',
    'io',
    'databases',
    'backups',
    'allocations',
    'egg_ids',
    'node_ids',
])]
#[RouteKey('id')]
#[Table(name: 'shop_plans')]
class ShopPlan extends Model
{
    public const string RESOURCE_NAME = 'shop_plan';

    protected $attributes = [
        'visible' => true,
        'sort' => 0,
        'swap' => 0,
        'io' => 500,
        'databases' => 0,
        'backups' => 0,
        'allocations' => 0,
        'egg_ids' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'visible' => 'boolean',
            'sort' => 'integer',
            'price_tokens' => 'integer',
            'memory' => 'integer',
            'swap' => 'integer',
            'disk' => 'integer',
            'cpu' => 'integer',
            'io' => 'integer',
            'databases' => 'integer',
            'backups' => 'integer',
            'allocations' => 'integer',
            'egg_ids' => 'array',
            'node_ids' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ShopCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ShopCategory::class, 'category_id');
    }

    /**
     * The plan's own resources, before add-ons.
     *
     * @return array{memory: int, swap: int, disk: int, io: int, cpu: int, databases: int, backups: int, allocations: int}
     */
    public function resources(): array
    {
        return [
            'memory' => $this->memory,
            'swap' => $this->swap,
            'disk' => $this->disk,
            'io' => $this->io,
            'cpu' => $this->cpu,
            'databases' => $this->databases,
            'backups' => $this->backups,
            'allocations' => $this->allocations,
        ];
    }

    /**
     * The eggs a player may pick for this plan.
     *
     * @return list<int>
     */
    public function eggIds(): array
    {
        return array_values(array_map(intval(...), $this->egg_ids));
    }

    /**
     * The nodes the plan is limited to, or null when any deployable node may host it.
     *
     * @return list<int>|null
     */
    public function nodeIds(): ?array
    {
        if ($this->node_ids === null || $this->node_ids === []) {
            return null;
        }

        return array_values(array_map(intval(...), $this->node_ids));
    }

    /** Whether a server of this plan may be placed on the node. */
    public function allowsNode(Node $node): bool
    {
        $nodeIds = $this->nodeIds();

        return $nodeIds === null ? $node->public : in_array($node->id, $nodeIds, true);
    }

    /**
     * Plans cheapest first, as the store lists them; the admin's sort breaks ties.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function cheapestFirst(Builder $query): void
    {
        $query->orderBy('price_tokens')->orderBy('sort')->orderBy('id');
    }
}
