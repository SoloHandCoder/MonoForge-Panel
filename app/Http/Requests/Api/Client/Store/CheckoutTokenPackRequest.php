<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Store;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class CheckoutTokenPackRequest extends ClientApiRequest
{
    /**
     * Only the pack is chosen by the player; its price and tokens come from the panel and Tebex.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'pack_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
