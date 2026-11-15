<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Plugins;

use Pterodactyl\Enum\Permissions;

/** Installing, updating and removing needs the `plugins.manage` subuser permission. */
class ManagePluginsRequest extends ReadPluginsRequest
{
    public function permission(): string
    {
        return Permissions::PluginsManage->value;
    }
}
