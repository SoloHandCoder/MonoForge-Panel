<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Plugins;

class InstalledPluginRequest extends ManagePluginsRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'file' => ['required', 'string', 'max:200'],
        ];
    }
}
