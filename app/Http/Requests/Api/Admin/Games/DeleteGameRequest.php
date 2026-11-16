<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Games;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class DeleteGameRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminGamesDelete];
    }
}
