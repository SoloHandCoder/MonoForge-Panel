<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Updates;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class GetUpdateStatusRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminVersionRead];
    }
}
