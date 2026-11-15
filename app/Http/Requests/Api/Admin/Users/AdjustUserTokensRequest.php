<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Users;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Services\Billing\TokenWallet;

class AdjustUserTokensRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminUsersUpdate];
    }

    /**
     * A signed whole number of tokens to add (positive) or remove (negative), and why.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'not_in:0', 'min:'.-TokenWallet::MAX_TOKENS, 'max:'.TokenWallet::MAX_TOKENS],
            'reason' => ['required', 'string', 'min:3', 'max:191'],
            'allow_negative' => ['sometimes', 'boolean'],
        ];
    }

    public function amount(): int
    {
        return $this->integer('amount');
    }

    public function reason(): string
    {
        return mb_trim($this->string('reason')->toString());
    }
}
