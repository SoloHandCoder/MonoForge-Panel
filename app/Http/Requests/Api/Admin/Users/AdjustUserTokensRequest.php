<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Users;

use Closure;
use Illuminate\Validation\Validator;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Models\User;

/**
 * Minting or removing tokens moves money, so it is limited to root administrators (no
 * delegated permission grants it), capped per call, and never allowed on your own wallet.
 */
class AdjustUserTokensRequest extends AdminApiRequest
{
    /** The most tokens one adjustment can add or remove. */
    public const int MAX_ADJUSTMENT = 1_000_000;

    public function permissions(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        return (bool) $this->user()->root_admin;
    }

    /**
     * A signed whole number of tokens to add (positive) or remove (negative), and why.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'not_in:0', 'min:'.-self::MAX_ADJUSTMENT, 'max:'.self::MAX_ADJUSTMENT],
            'reason' => ['required', 'string', 'min:3', 'max:191'],
            'allow_negative' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $target = $this->route('user');
                if ($target instanceof User && $target->id === $this->user()->id) {
                    $validator->errors()->add('amount', 'You cannot adjust your own token balance.');
                }
            },
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
