<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Nodes;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Services\Tunnels\PlayitKey;

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
            'secret' => ['nullable', 'string', 'regex:'.PlayitKey::SECRET_PATTERN],
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'agent_id.uuid' => 'The Agent ID must be the agent_id from playit.toml, such as 5b9d1b0e-3c1f-4e8a-9a51-1f0e7d2c6b44.',
            'secret.regex' => 'The Agent Secret does not look like a playit.gg agent key. Paste only the secret_key value from the playit.toml the agent uses (often /etc/playit/playit.toml), without quotes.',
        ];
    }

    /**
     * Values pasted straight out of playit.toml (`secret_key = "ab12..."`) are cleaned before
     * they are validated and stored.
     */
    protected function prepareForValidation(): void
    {
        foreach (['agent_id' => PlayitKey::AGENT_ID, 'secret' => PlayitKey::SECRET_KEY] as $field => $name) {
            if ($this->has($field)) {
                $value = PlayitKey::clean($this->input($field), $name);
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }
}
