<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Account;

use Pterodactyl\Actions\Users\CreateUser;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

/**
 * The first password of an account created through Discord or Google, which has no
 * current password to confirm.
 */
class SetInitialPasswordRequest extends ClientApiRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', ...CreateUser::passwordRules()],
        ];
    }
}
