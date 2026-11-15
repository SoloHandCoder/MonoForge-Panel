<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Account\Friends;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class StoreFriendRequestRequest extends ClientApiRequest
{
    /**
     * The username or email of the user to ask. Any value of the right shape is accepted, so the
     * response never shows whether a user has it.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'user' => ['required', 'string', 'min:1', 'max:191'],
        ];
    }
}
