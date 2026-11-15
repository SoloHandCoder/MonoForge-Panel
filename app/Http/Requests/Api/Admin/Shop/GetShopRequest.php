<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

/** Reading anything in the admin Shop tab (catalog, Tebex settings, token packs, payments). */
class GetShopRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminShopRead];
    }
}
