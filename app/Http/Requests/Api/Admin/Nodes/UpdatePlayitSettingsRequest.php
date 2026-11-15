<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Nodes;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class UpdatePlayitSettingsRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminNodesUpdate];
    }

    /**
     * Validation rules for the node's playit.gg agent. A blank secret keeps the stored one; an
     * empty agent id removes the agent and its secret.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'agent_id' => ['present', 'nullable', 'uuid'],
            'secret' => ['nullable', 'string', 'max:512'],
        ];
    }

    /** Rename fields to be more clear in error messages. */
    public function attributes(): array
    {
        return [
            'agent_id' => 'Agent ID',
            'secret' => 'Agent Secret',
        ];
    }
}
