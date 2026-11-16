<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Client;

use Illuminate\Http\Request;
use Pterodactyl\Models\Server;

/**
 * A finished store purchase: the server that is now installing and what the first period cost.
 *
 * @extends ClientResource<array{server: Server, tokens_charged: int, period_days: int, replayed: bool, address: string|null, discount_tokens: int, coupon: string|null}>
 */
class StorePurchaseResource extends ClientResource
{
    public const string RESOURCE_NAME = 'store_purchase';

    public function resourceName(): string
    {
        return self::RESOURCE_NAME;
    }

    public function toArray(Request $request): array
    {
        $server = $this->resource['server'];

        return [
            'server' => [
                'identifier' => $server->uuidShort,
                'uuid' => $server->uuid,
                'name' => $server->name,
            ],
            'tokens_charged' => $this->resource['tokens_charged'],
            'period_days' => $this->resource['period_days'],
            'replayed' => $this->resource['replayed'],
            'address' => $this->resource['address'],
            'discount_tokens' => $this->resource['discount_tokens'],
            'coupon' => $this->resource['coupon'],
        ];
    }
}
