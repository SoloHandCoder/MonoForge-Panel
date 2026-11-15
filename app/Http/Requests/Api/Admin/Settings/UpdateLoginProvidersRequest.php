<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Settings;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class UpdateLoginProvidersRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminSettingsUpdate];
    }

    /**
     * A blank client secret keeps the stored one.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        $rules = [];
        foreach (['discord', 'google'] as $provider) {
            $rules[$provider] = ['required', 'array'];
            $rules[$provider.'.enabled'] = ['required', 'boolean'];
            $rules[$provider.'.client_id'] = ['nullable', 'string', 'max:191'];
            $rules[$provider.'.client_secret'] = ['nullable', 'string', 'max:191'];
        }

        return $rules;
    }
}
