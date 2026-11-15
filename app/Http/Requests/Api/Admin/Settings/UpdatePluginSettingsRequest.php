<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Settings;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class UpdatePluginSettingsRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminSettingsUpdate];
    }

    /**
     * A blank key keeps the stored one; clear_curseforge_key removes it.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'curseforge_key' => ['nullable', 'string', 'max:255'],
            'clear_curseforge_key' => ['sometimes', 'boolean'],
        ];
    }
}
