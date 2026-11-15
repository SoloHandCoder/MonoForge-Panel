<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Files;

use Pterodactyl\Contracts\Http\ClientPermissionsRequest;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class SearchFilesRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    /**
     * A search only reveals names, so it needs the same permission as listing a directory.
     */
    public function permission(): string
    {
        return Permissions::FileRead->value;
    }

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'max:191'],
            'directory' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }
}
