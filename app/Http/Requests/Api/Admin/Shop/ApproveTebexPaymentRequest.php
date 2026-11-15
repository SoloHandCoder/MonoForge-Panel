<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Models\User;

/**
 * Crediting a flagged payment mints tokens, so like a token adjustment it is limited to root
 * administrators (no delegated permission grants it).
 */
class ApproveTebexPaymentRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        return (bool) $this->user()->root_admin;
    }

    /**
     * An optional panel user to credit, for a payment that matched none.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function recipient(): ?User
    {
        return $this->filled('user_id') ? User::query()->find($this->integer('user_id')) : null;
    }
}
