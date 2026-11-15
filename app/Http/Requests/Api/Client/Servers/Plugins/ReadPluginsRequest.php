<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Plugins;

use Illuminate\Validation\Rule;
use Pterodactyl\Contracts\Http\ClientPermissionsRequest;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Services\Plugins\ServerPlatform;

/**
 * Browsing plugins and the installed list needs `file.read`. The loader and game version the
 * panel detected can be overridden by the user for each request.
 */
class ReadPluginsRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    public const string ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_\-.]{0,63}$/';

    /** @return ValidationRules */
    public static function platformRules(): array
    {
        return [
            'loader' => ['sometimes', 'nullable', 'string', Rule::in(ServerPlatform::loaders())],
            'game_version' => ['sometimes', 'nullable', 'string', 'max:16', 'regex:/^\d{1,2}\.\d{1,2}(\.\d{1,2})?(-(pre|rc)\d{1,2})?$/'],
        ];
    }

    public function permission(): string
    {
        return Permissions::FileRead->value;
    }

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return self::platformRules();
    }

    public function loaderOverride(): ?string
    {
        return $this->filled('loader') ? $this->string('loader')->toString() : null;
    }

    public function gameVersionOverride(): ?string
    {
        return $this->filled('game_version') ? $this->string('game_version')->toString() : null;
    }
}
