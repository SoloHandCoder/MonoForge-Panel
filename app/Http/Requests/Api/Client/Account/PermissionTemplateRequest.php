<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Account;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Services\Subusers\PermissionTemplateRules;

/**
 * Creates or updates one of the user's own subuser permission templates.
 */
class PermissionTemplateRequest extends ClientApiRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return PermissionTemplateRules::rules();
    }
}
