<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Client;

use Illuminate\Http\Request;
use Pterodactyl\Models\Server;

/**
 * A free trial server that was just started: the server that is now installing and when the
 * trial ends.
 *
 * @extends ClientResource<array{server: Server, hours: int, ends_at: string|null, replayed: bool, address: string|null}>
 */
class StoreTrialResource extends ClientResource
{
    public const string RESOURCE_NAME = 'store_trial';

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
            'hours' => $this->resource['hours'],
            'ends_at' => $this->resource['ends_at'],
            'replayed' => $this->resource['replayed'],
            'address' => $this->resource['address'],
        ];
    }
}
