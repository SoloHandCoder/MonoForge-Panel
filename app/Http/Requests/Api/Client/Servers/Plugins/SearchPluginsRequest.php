<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Plugins;

use Illuminate\Validation\Rule;
use Pterodactyl\Enum\MarketplaceProjectType;
use Pterodactyl\Enum\MarketplaceSource;

class SearchPluginsRequest extends ReadPluginsRequest
{
    public const int MAX_PAGE = 50;

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'source' => ['required', 'string', Rule::in(MarketplaceSource::values())],
            'type' => ['sometimes', 'nullable', 'string', Rule::in(MarketplaceProjectType::values())],
            'query' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
            'sort' => ['sometimes', 'string', Rule::in(['relevance', 'downloads', 'updated', 'newest'])],
        ];
    }
}
