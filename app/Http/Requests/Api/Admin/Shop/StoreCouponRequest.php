<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Models\Billing\Coupon;
use Pterodactyl\Services\Billing\TokenWallet;

/**
 * Creates or updates a coupon. A free-tokens coupon mints tokens, so like a token adjustment
 * only a root administrator may create or change one.
 */
class StoreCouponRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminShopUpdate];
    }

    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        $existing = $this->route('coupon');
        $mintsTokens = $this->input('type') === Coupon::TYPE_TOKENS || ($existing instanceof Coupon && $existing->type === Coupon::TYPE_TOKENS);

        return ! $mintsTokens || $this->user()->root_admin;
    }

    /**
     * `value` is a percentage (1-100) for `percent`, tokens off the first period for `fixed`
     * and tokens credited for `tokens`. Empty plan and category lists allow every plan.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        $existing = $this->route('coupon');
        $max = match ($this->input('type')) {
            Coupon::TYPE_PERCENT => 100,
            Coupon::TYPE_TOKENS => Coupon::MAX_TOKENS,
            default => TokenWallet::MAX_TOKENS,
        };

        return [
            'code' => ['required', 'string', 'regex:'.Coupon::CODE_PATTERN, Rule::unique('coupons', 'code')->ignore($existing instanceof Coupon ? $existing->id : null)],
            'type' => ['required', 'string', Rule::in(Coupon::TYPES)],
            'value' => ['required', 'integer', 'min:1', 'max:'.$max],
            'description' => ['nullable', 'string', 'max:191'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', ...($this->filled('valid_from') ? ['after:valid_from'] : [])],
            'plan_ids' => ['nullable', 'array', 'max:500'],
            'plan_ids.*' => ['integer', 'distinct', 'exists:shop_plans,id'],
            'category_ids' => ['nullable', 'array', 'max:500'],
            'category_ids.*' => ['integer', 'distinct', 'exists:shop_categories,id'],
            'new_customers_only' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.regex' => 'A code is 3 to 32 letters, digits, hyphens or underscores.'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $existing = $this->route('coupon');
            if ($existing instanceof Coupon && $existing->redemptions_count > 0 && $this->input('type') !== $existing->type) {
                $validator->errors()->add('type', 'The type of a coupon that was already used cannot change. Create a new coupon instead.');
            }
        });
    }

    /**
     * @return array{code: string, type: string, value: int, description: string|null, max_redemptions: int|null, valid_from: CarbonImmutable|null, valid_until: CarbonImmutable|null, plan_ids: list<int>|null, category_ids: list<int>|null, new_customers_only: bool, enabled: bool}
     */
    public function couponAttributes(): array
    {
        $ids = function (string $key): ?array {
            $ids = [];
            foreach ((array) $this->validated($key, []) as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }

            return $ids === [] ? null : $ids;
        };
        $date = fn (string $key): ?CarbonImmutable => $this->filled($key) ? CarbonImmutable::parse($this->string($key)->toString()) : null;
        $description = $this->filled('description') ? $this->string('description')->trim()->toString() : null;
        $type = $this->string('type')->toString();

        return [
            'code' => $this->string('code')->toString(),
            'type' => $type,
            'value' => $this->integer('value'),
            'description' => $description === '' ? null : $description,
            'max_redemptions' => $this->filled('max_redemptions') ? $this->integer('max_redemptions') : null,
            'valid_from' => $date('valid_from'),
            'valid_until' => $date('valid_until'),
            // Plan restrictions only apply to discounts at the store checkout.
            'plan_ids' => $type === Coupon::TYPE_TOKENS ? null : $ids('plan_ids'),
            'category_ids' => $type === Coupon::TYPE_TOKENS ? null : $ids('category_ids'),
            'new_customers_only' => $this->boolean('new_customers_only'),
            'enabled' => $this->boolean('enabled', true),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Coupon::normalize($this->input('code'))]);
        }
    }
}
