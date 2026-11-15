<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class StoreShopPeriodRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminShopUpdate];
    }

    /**
     * A negative modifier is a discount, a positive one a surcharge.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
            'price_modifier_percent' => ['required', 'integer', 'min:-90', 'max:500'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{name: string, days: int, price_modifier_percent: int, sort: int, enabled: bool}
     */
    public function periodAttributes(): array
    {
        return [
            'name' => $this->string('name')->trim()->toString(),
            'days' => $this->integer('days'),
            'price_modifier_percent' => $this->integer('price_modifier_percent'),
            'sort' => $this->integer('sort'),
            'enabled' => $this->boolean('enabled', true),
        ];
    }
}
