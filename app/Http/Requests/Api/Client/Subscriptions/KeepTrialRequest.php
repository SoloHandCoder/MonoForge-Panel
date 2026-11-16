<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Subscriptions;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class KeepTrialRequest extends ClientApiRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'period_id' => ['required', 'integer', 'min:1'],
            'expected_total' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['period_id' => 'Billing period'];
    }
}
