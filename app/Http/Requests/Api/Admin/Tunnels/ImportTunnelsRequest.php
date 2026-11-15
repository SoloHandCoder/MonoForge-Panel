<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Tunnels;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class ImportTunnelsRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminServersUpdate];
    }

    /**
     * Validation rules for importing tunnels made by hand on playit.gg: every node with an
     * agent, or only the one in `?node=`.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'node' => ['sometimes', 'integer', 'exists:nodes,id'],
        ];
    }
}
