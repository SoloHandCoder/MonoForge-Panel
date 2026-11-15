<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Servers\Subscriptions;

use Pterodactyl\Http\Requests\Api\Admin\Servers\ServerWriteRequest;

class ExtendServerSubscriptionRequest extends ServerWriteRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }
}
