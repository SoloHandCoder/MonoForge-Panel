<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Services\Billing\ShopSettings;

class UpdateShopSettingsRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminShopUpdate];
    }

    /**
     * A limit of 0 sells without limit; a grace period of 0 deletes a server as soon as it is suspended.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:'.ShopSettings::MAX_GRACE_DAYS],
            'low_balance_days' => ['required', 'integer', 'min:0', 'max:30'],
            'limits' => ['required', 'array'],
            'limits.memory' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'limits.disk' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'limits.cpu' => ['required', 'integer', 'min:0', 'max:10000000'],
        ];
    }

    /**
     * @return array{enabled: bool, grace_days: int, low_balance_days: int, limits: array{memory: int, disk: int, cpu: int}}
     */
    public function settings(): array
    {
        return [
            'enabled' => $this->boolean('enabled'),
            'grace_days' => $this->integer('grace_days'),
            'low_balance_days' => $this->integer('low_balance_days'),
            'limits' => [
                'memory' => $this->integer('limits.memory'),
                'disk' => $this->integer('limits.disk'),
                'cpu' => $this->integer('limits.cpu'),
            ],
        ];
    }
}
