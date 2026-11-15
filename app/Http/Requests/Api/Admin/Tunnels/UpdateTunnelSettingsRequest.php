<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Tunnels;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class UpdateTunnelSettingsRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminSettingsUpdate];
    }

    /**
     * Validation rules for the custom subdomain settings.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'subdomains_enabled' => ['required', 'boolean'],
            'base_domain' => ['nullable', 'required_if:subdomains_enabled,true', 'string', 'max:191', 'regex:/^(?=.{1,191}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'blocklist' => ['present', 'array', 'max:500'],
            'blocklist.*' => ['nullable', 'string', 'max:63'],
        ];
    }

    /** Rename fields to be more clear in error messages. */
    public function attributes(): array
    {
        return [
            'subdomains_enabled' => 'Custom Subdomains',
            'base_domain' => 'Base Domain',
            'blocklist' => 'Reserved Names',
        ];
    }
}
