<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Support;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

/**
 * Reading the support queue and its tickets.
 */
class SupportReadRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminSupportRead];
    }
}
