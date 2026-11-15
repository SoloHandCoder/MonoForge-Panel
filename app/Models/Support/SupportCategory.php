<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Support;

use Carbon\CarbonImmutable;
use Database\Factories\Support\SupportCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pterodactyl\Models\Model;

/**
 * A ticket category, such as Billing or Server problem. Administrators edit the list.
 *
 * @property int $id
 * @property string $name
 * @property int $sort
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('support_categories')]
#[Fillable(['name', 'sort'])]
#[RouteKey('id')]
class SupportCategory extends Model
{
    /** @use HasFactory<SupportCategoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sort' => 'int'];
    }

    /**
     * @return Builder<self>
     */
    public static function ordered(): Builder
    {
        return self::query()->orderBy('sort')->orderBy('id');
    }

    /**
     * @return HasMany<SupportTicket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'category_id');
    }
}
