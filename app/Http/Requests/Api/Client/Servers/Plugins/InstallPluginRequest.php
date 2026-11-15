<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Plugins;

use Illuminate\Validation\Rule;
use Pterodactyl\Enum\MarketplaceSource;

class InstallPluginRequest extends ManagePluginsRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'source' => ['required', 'string', Rule::in(MarketplaceSource::values())],
            'project' => ['required', 'string', 'regex:'.self::ID_PATTERN],
            'version' => ['required', 'string', 'regex:'.self::ID_PATTERN],
        ];
    }
}
