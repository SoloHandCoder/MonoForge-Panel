<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Updates;

use Illuminate\Validation\Rule;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Services\MonoForge\MonoForgeUpdateService;

/**
 * Choosing whether the panel installs builds by itself is limited to root administrators.
 */
class UpdateUpdateSettingsRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [];
    }

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(MonoForgeUpdateService::MODES)],
        ];
    }
}
