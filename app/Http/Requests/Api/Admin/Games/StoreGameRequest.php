<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Games;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Services\Games\GameService;

class StoreGameRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminGamesCreate];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return GameService::rules(creating: true);
    }
}
