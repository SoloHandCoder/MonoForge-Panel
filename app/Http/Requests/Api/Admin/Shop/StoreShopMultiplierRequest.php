<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Illuminate\Validation\Rule;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Enum\ShopMultiplierScope;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Models\Billing\ShopMultiplier;

class StoreShopMultiplierRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminShopUpdate];
    }

    /**
     * One multiplier per location or node.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        $multiplier = $this->route('multiplier');
        $scope = $this->string('scope')->toString();
        $table = $scope === ShopMultiplierScope::Node->value ? 'nodes' : 'locations';

        return [
            'scope' => ['required', 'string', Rule::enum(ShopMultiplierScope::class)],
            'scope_id' => [
                'required',
                'integer',
                'exists:'.$table.',id',
                Rule::unique('shop_multipliers', 'scope_id')
                    ->where('scope', $scope)
                    ->ignore($multiplier instanceof ShopMultiplier ? $multiplier->id : null),
            ],
            'multiplier' => ['required', 'numeric', 'min:0.01', 'max:999.99', 'decimal:0,2'],
            'label' => ['nullable', 'string', 'max:191'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['scope_id.unique' => 'That location or node already has a multiplier.'];
    }

    /**
     * @return array{scope: ShopMultiplierScope, scope_id: int, multiplier: string, label: string|null}
     */
    public function multiplierAttributes(): array
    {
        return [
            'scope' => ShopMultiplierScope::from($this->string('scope')->toString()),
            'scope_id' => $this->integer('scope_id'),
            'multiplier' => number_format($this->float('multiplier'), 2, '.', ''),
            'label' => $this->filled('label') ? $this->string('label')->trim()->toString() : null,
        ];
    }
}
