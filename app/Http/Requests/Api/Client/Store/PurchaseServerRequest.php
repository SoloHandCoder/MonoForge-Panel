<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Store;

use Pterodactyl\Data\StorePurchaseOrder;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class PurchaseServerRequest extends ClientApiRequest
{
    /** Lowercase letters, digits and inner hyphens, as a DNS label allows. */
    public const string SUBDOMAIN_REGEX = '/^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])$/';

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', 'min:1'],
            'period_id' => ['required', 'integer', 'min:1'],
            'egg_id' => ['required', 'integer', 'min:1'],
            'location_id' => ['nullable', 'integer', 'min:1'],
            'node_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'min:1', 'max:60', 'regex:/^[^<>]+$/u'],
            'addons' => ['sometimes', 'array'],
            'addons.*' => ['integer', 'min:0', 'max:1000'],
            'subdomain' => ['nullable', 'string', 'regex:'.self::SUBDOMAIN_REGEX],
            'expected_total' => ['nullable', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'plan_id' => 'Plan',
            'period_id' => 'Billing period',
            'egg_id' => 'Game',
            'name' => 'Server name',
            'addons.*' => 'Add-on units',
            'subdomain' => 'Subdomain',
            'idempotency_key' => 'Idempotency key',
        ];
    }

    public function order(): StorePurchaseOrder
    {
        $addons = [];
        foreach ((array) $this->validated('addons', []) as $key => $units) {
            if (is_string($key) && is_numeric($units)) {
                $addons[$key] = (int) $units;
            }
        }

        $subdomain = $this->filled('subdomain') ? mb_strtolower($this->string('subdomain')->toString()) : null;

        return new StorePurchaseOrder(
            planId: $this->integer('plan_id'),
            periodId: $this->integer('period_id'),
            eggId: $this->integer('egg_id'),
            name: mb_trim($this->string('name')->toString()),
            idempotencyKey: $this->string('idempotency_key')->toString(),
            locationId: $this->filled('location_id') ? $this->integer('location_id') : null,
            nodeId: $this->filled('node_id') ? $this->integer('node_id') : null,
            addons: $addons,
            expectedTotal: $this->filled('expected_total') ? $this->integer('expected_total') : null,
            subdomain: $subdomain,
        );
    }
}
