<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Support;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class GetSupportTicketsRequest extends ClientApiRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }
}
