<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class UpdateShopNodeRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminShopUpdate];
    }

    /**
     * Whether the store may sell new servers on the node, and how much it may sell there (0 = no
     * limit). Either one, or both.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'shop_enabled' => ['required_without:limits', 'boolean'],
            'limits' => ['required_without:shop_enabled', 'array'],
            'limits.memory' => ['required_with:limits', 'integer', 'min:0', 'max:1000000000'],
            'limits.disk' => ['required_with:limits', 'integer', 'min:0', 'max:1000000000'],
            'limits.cpu' => ['required_with:limits', 'integer', 'min:0', 'max:10000000'],
        ];
    }

    /**
     * The node's new shop limits, or null when the request leaves them alone.
     *
     * @return array{memory: int, disk: int, cpu: int}|null
     */
    public function limits(): ?array
    {
        if (! $this->has('limits')) {
            return null;
        }

        return [
            'memory' => $this->integer('limits.memory'),
            'disk' => $this->integer('limits.disk'),
            'cpu' => $this->integer('limits.cpu'),
        ];
    }
}
