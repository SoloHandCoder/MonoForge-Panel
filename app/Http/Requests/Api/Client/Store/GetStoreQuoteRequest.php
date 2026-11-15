<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Store;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class GetStoreQuoteRequest extends ClientApiRequest
{
    /**
     * A node, or a location to pick the plan's cheapest node in. Add-ons are units per add-on key.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer'],
            'period_id' => ['required', 'integer'],
            'node_id' => ['nullable', 'integer', 'required_without:location_id'],
            'location_id' => ['nullable', 'integer'],
            'addons' => ['sometimes', 'array', 'max:50'],
            'addons.*' => ['integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function addons(): array
    {
        $addons = [];
        foreach ((array) $this->input('addons', []) as $key => $units) {
            $addons[(string) $key] = is_numeric($units) ? (int) $units : 0;
        }

        return $addons;
    }
}
