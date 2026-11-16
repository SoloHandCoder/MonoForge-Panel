<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Nodes;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Models\Node;

class UpdateMinecraftEntranceRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminNodesUpdate];
    }

    /**
     * The shape of the settings; whether they fit the node is checked by EntranceSettings.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'domain' => ['nullable', 'string', 'max:200'],
            'mode' => ['nullable', 'string', 'in:'.Node::ENTRANCE_PLAYIT.','.Node::ENTRANCE_GATEWAY],
            'entrance_port' => ['nullable', 'integer'],
            'proxy_port' => ['nullable', 'integer'],
            'proxy_protocol' => ['nullable', 'boolean'],
            'srv' => ['nullable', 'boolean'],
        ];
    }
}
