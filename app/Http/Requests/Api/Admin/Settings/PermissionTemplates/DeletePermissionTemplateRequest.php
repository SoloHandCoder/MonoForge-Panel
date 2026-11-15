<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Settings\PermissionTemplates;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class DeletePermissionTemplateRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminSettingsUpdate];
    }
}
