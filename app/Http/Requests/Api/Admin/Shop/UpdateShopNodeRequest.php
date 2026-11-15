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
     * Whether the store may sell new servers on the node.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'shop_enabled' => ['required', 'boolean'],
        ];
    }
}
