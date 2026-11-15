<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Settings\PermissionTemplates;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Services\Subusers\PermissionTemplateRules;

/**
 * Creates or updates a global subuser permission template.
 */
class StorePermissionTemplateRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminSettingsUpdate];
    }

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return PermissionTemplateRules::rules();
    }
}
