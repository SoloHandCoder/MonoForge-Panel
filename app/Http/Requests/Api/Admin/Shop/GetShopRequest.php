<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

/** Reading the token shop's Tebex settings, token packs and payments. */
class GetShopRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminSettingsRead];
    }
}
