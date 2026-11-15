<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class GetShopRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminShopRead];
    }
}
