<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Help;

use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

/** The guides for running the panel: root administrators only. */
class GetAdminHelpRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [];
    }
}
