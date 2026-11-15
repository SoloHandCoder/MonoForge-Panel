<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Settings;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Services\Status\StatusPageSettings;

class UpdateStatusPageSettingsRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminSettingsUpdate];
    }

    /**
     * Whether the public status page is on, and per node whether it is shown and under
     * which name. A blank name uses the default one.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'nodes' => ['present', 'array', 'max:500'],
            'nodes.*.id' => ['required', 'integer', 'distinct', 'exists:nodes,id'],
            'nodes.*.visible' => ['required', 'boolean'],
            'nodes.*.display_name' => ['nullable', 'string', 'max:'.StatusPageSettings::NAME_MAX_LENGTH],
        ];
    }

    /** Rename fields to be more clear in error messages. */
    public function attributes(): array
    {
        return [
            'enabled' => 'Status Page Enabled',
            'nodes.*.id' => 'Node',
            'nodes.*.visible' => 'Shown',
            'nodes.*.display_name' => 'Display Name',
        ];
    }
}
