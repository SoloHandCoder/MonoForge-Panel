<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Support;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

/**
 * Answering tickets and managing the support tools.
 */
class SupportReplyRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminSupportReply];
    }
}
