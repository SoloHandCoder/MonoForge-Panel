<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Store;

use Pterodactyl\Data\StoreTrialOrder;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class StartTrialRequest extends ClientApiRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', 'min:1'],
            'egg_id' => ['required', 'integer', 'min:1'],
            'location_id' => ['nullable', 'integer', 'min:1'],
            'node_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'min:1', 'max:60', 'regex:/^[^<>]+$/u'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            // Checked by the captcha middleware when the panel uses one.
            'g-recaptcha-response' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'plan_id' => 'Plan',
            'egg_id' => 'Game',
            'name' => 'Server name',
            'idempotency_key' => 'Idempotency key',
        ];
    }

    public function order(): StoreTrialOrder
    {
        return new StoreTrialOrder(
            planId: $this->integer('plan_id'),
            eggId: $this->integer('egg_id'),
            name: mb_trim($this->string('name')->toString()),
            idempotencyKey: $this->string('idempotency_key')->toString(),
            locationId: $this->filled('location_id') ? $this->integer('location_id') : null,
            nodeId: $this->filled('node_id') ? $this->integer('node_id') : null,
        );
    }
}
