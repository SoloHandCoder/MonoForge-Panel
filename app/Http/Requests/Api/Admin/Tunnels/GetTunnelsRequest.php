<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Tunnels;

use Illuminate\Validation\Rule;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class GetTunnelsRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminServersRead];
    }

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'status' => ['sometimes', 'string', Rule::enum(TunnelStatus::class)],
        ];
    }
}
