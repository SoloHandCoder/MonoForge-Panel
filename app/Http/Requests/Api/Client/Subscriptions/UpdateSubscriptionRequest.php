<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Subscriptions;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class UpdateSubscriptionRequest extends ClientApiRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'auto_renew' => ['required_without:auto_sleep', 'boolean'],
            'auto_sleep' => ['required_without:auto_renew', 'boolean'],
        ];
    }
}
