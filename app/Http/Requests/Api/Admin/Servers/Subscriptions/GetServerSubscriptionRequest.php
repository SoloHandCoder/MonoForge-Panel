<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Servers\Subscriptions;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class GetServerSubscriptionRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminServersRead];
    }
}
