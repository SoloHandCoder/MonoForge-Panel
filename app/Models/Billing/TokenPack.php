<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Pterodactyl\Models\Model;

/**
 * A fixed Tebex package, such as "500 tokens", and the tokens a payment for it credits.
 *
 * @property int $id
 * @property string $tebex_package_id
 * @property string $name
 * @property int $tokens
 * @property int $bonus_tokens
 * @property bool $visible
 * @property int $sort
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['tebex_package_id', 'name', 'tokens', 'bonus_tokens', 'visible', 'sort'])]
#[RouteKey('id')]
class TokenPack extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tokens' => 'integer',
            'bonus_tokens' => 'integer',
            'visible' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** The tokens one unit of this pack credits, bonus included. */
    public function totalTokens(): int
    {
        return $this->tokens + $this->bonus_tokens;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'tebex_package_id' => $this->tebex_package_id,
            'name' => $this->name,
            'tokens' => $this->tokens,
            'bonus_tokens' => $this->bonus_tokens,
            // Not $this->visible: that is Eloquent's own serialization property.
            'visible' => (bool) $this->getAttribute('visible'),
            'sort' => $this->sort,
        ];
    }

    /**
     * Packs in the order the store shows them.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('tokens')->orderBy('id');
    }
}
