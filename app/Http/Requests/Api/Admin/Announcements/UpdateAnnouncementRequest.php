<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Announcements;

use Pterodactyl\Enum\Permissions;

class UpdateAnnouncementRequest extends StoreAnnouncementRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminAnnouncementsUpdate];
    }
}
