<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Minecraft;

use Illuminate\Validation\Rule;
use Pterodactyl\Contracts\Http\ClientPermissionsRequest;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Services\Games\Minecraft\MinecraftSoftware;
use Pterodactyl\Services\Games\Minecraft\MinecraftVersionCatalog;

/**
 * Switching the software needs `versions.manage`: it replaces the server jar (code that runs on
 * the server) and can reinstall the server, so it is a permission of its own.
 */
class InstallMinecraftVersionRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    public function permission(): string
    {
        return Permissions::VersionsManage->value;
    }

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'software' => ['required', 'string', Rule::enum(MinecraftSoftware::class)],
            'version' => ['required', 'string', 'max:64', 'regex:'.MinecraftVersionCatalog::VERSION_PATTERN],
        ];
    }

    public function software(): MinecraftSoftware
    {
        return MinecraftSoftware::from($this->string('software')->toString());
    }
}
