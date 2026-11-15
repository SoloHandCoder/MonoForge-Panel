<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Tunnels;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class GetUnlinkedTunnelsRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminServersRead];
    }
}
