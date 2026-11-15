<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Subusers;

use Illuminate\Validation\Rule;
use Pterodactyl\Services\Servers\SubuserPermissionCatalog;

/**
 * The fields of a subuser permission template, shared by the account and admin endpoints.
 */
final class PermissionTemplateRules
{
    /**
     * @return ValidationRules
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:64'],
            'permissions' => ['present', 'array', 'max:500'],
            'permissions.*' => ['string', Rule::in(resolve(SubuserPermissionCatalog::class)->keys())],
        ];
    }
}
