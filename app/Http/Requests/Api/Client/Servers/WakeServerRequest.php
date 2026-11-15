<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers;

use Pterodactyl\Contracts\Http\ClientPermissionsRequest;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class WakeServerRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    /** Waking starts the server, so it needs the start permission. */
    public function permission(): string
    {
        return Permissions::ControlStart->value;
    }
}
