<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Plugins;

use Illuminate\Validation\Rule;
use Pterodactyl\Enum\MarketplaceProjectType;
use Pterodactyl\Enum\MarketplaceSource;

class ListPluginVersionsRequest extends ReadPluginsRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'source' => ['required', 'string', Rule::in(MarketplaceSource::values())],
            'type' => ['sometimes', 'nullable', 'string', Rule::in(MarketplaceProjectType::values())],
            'project' => ['required', 'string', 'regex:'.self::ID_PATTERN],
        ];
    }
}
