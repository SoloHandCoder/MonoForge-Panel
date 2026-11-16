<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Network;

use Closure;
use Pterodactyl\Contracts\Http\ClientPermissionsRequest;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Entrance\EntranceHostnames;

class UpdateMinecraftHostnameRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    /** The same permission as renaming the server: the name is part of how players find it. */
    public function permission(): string
    {
        return Permissions::SettingsRename->value;
    }

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        $server = $this->route('server');

        return [
            'hostname' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($server): void {
                $error = is_string($value) ? EntranceHostnames::error(mb_strtolower(mb_trim($value)), $server instanceof Server ? $server : null) : null;
                if ($error !== null) {
                    $fail($error);
                }
            }],
        ];
    }
}
