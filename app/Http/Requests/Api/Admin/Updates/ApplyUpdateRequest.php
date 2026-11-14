<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Updates;

use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

/**
 * Installing a MonoForge build replaces the panel's code, so only root administrators may.
 */
class ApplyUpdateRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [];
    }
}
