<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Plugins;

class InstallModpackRequest extends ManagePluginsRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'project' => ['required', 'string', 'regex:'.self::ID_PATTERN],
            'version' => ['required', 'string', 'regex:'.self::ID_PATTERN],
            'confirm_without_backup' => ['sometimes', 'boolean'],
        ];
    }
}
