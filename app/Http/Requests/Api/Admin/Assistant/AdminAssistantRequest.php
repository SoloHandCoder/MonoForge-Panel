<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Assistant;

use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

/** The admin assistant, its providers, settings and usage: root administrators only. */
class AdminAssistantRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [];
    }
}
