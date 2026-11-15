<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Tunnels;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class StoreTunnelRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminServersUpdate];
    }

    /**
     * Validation rules for creating a tunnel for a server that has none, such as one created
     * before its node had a playit.gg agent.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'server_id' => ['required', 'integer', 'exists:servers,id'],
        ];
    }
}
