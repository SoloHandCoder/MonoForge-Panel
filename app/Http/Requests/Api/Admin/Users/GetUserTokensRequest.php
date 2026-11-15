<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Users;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class GetUserTokensRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminUsersRead];
    }

    /**
     * Pagination of the user's token ledger.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }
}
