<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Illuminate\Validation\Rule;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Enum\ShopAddonResource;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Models\Billing\ShopAddon;

class StoreShopAddonRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminShopUpdate];
    }

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        $addon = $this->route('addon');

        return [
            'key' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
                Rule::unique('shop_addons', 'key')->ignore($addon instanceof ShopAddon ? $addon->id : null),
            ],
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'resource' => ['required', 'string', Rule::enum(ShopAddonResource::class)],
            'amount' => ['required', 'integer', 'min:1', 'max:1048576'],
            'price_tokens' => ['required', 'integer', 'min:0', 'max:100000000'],
            'max_units' => ['required', 'integer', 'min:1', 'max:100'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{key: string, name: string, description: string|null, resource: ShopAddonResource, amount: int, price_tokens: int, max_units: int, enabled: bool}
     */
    public function addonAttributes(): array
    {
        return [
            'key' => $this->string('key')->trim()->toString(),
            'name' => $this->string('name')->trim()->toString(),
            'description' => $this->filled('description') ? $this->string('description')->trim()->toString() : null,
            'resource' => ShopAddonResource::from($this->string('resource')->toString()),
            'amount' => $this->integer('amount'),
            'price_tokens' => $this->integer('price_tokens'),
            'max_units' => $this->integer('max_units'),
            'enabled' => $this->boolean('enabled', true),
        ];
    }
}
