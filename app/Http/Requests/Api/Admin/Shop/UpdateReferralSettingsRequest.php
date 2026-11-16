<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Services\Billing\ReferralSettings;

/**
 * The referral reward mints tokens, so only root administrators change the programme.
 */
class UpdateReferralSettingsRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [];
    }

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'reward_tokens' => ['required', 'integer', 'min:1', 'max:'.ReferralSettings::MAX_REWARD],
            'monthly_cap' => ['required', 'integer', 'min:0', 'max:'.ReferralSettings::MAX_MONTHLY_CAP],
            'hold_days' => ['sometimes', 'integer', 'min:1', 'max:'.ReferralSettings::MAX_HOLD_DAYS],
            'min_purchase_tokens' => ['sometimes', 'integer', 'min:0', 'max:'.ReferralSettings::MAX_MIN_PURCHASE],
        ];
    }
}
