<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Illuminate\Validation\Rule;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Services\Billing\TrialSettings;

class UpdateShopTrialsRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminShopUpdate];
    }

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'plan_ids' => ['present', 'array', 'max:100'],
            'plan_ids.*' => ['integer', 'distinct', 'exists:shop_plans,id'],
            'hours' => ['required', 'integer', Rule::in(TrialSettings::HOURS)],
            'max_concurrent' => ['required', 'integer', 'min:0', 'max:'.TrialSettings::MAX_CONCURRENT],
            'daily_cap' => ['sometimes', 'integer', 'min:0', 'max:'.TrialSettings::MAX_DAILY_CAP],
            'require_verified' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array{enabled: bool, plan_ids: list<int>, hours: int, max_concurrent: int, daily_cap: int, require_verified: bool}
     */
    public function settings(): array
    {
        $planIds = [];
        foreach ((array) $this->validated('plan_ids', []) as $id) {
            if (is_numeric($id)) {
                $planIds[] = (int) $id;
            }
        }

        return [
            'enabled' => $this->boolean('enabled'),
            'plan_ids' => $planIds,
            'hours' => $this->integer('hours'),
            'max_concurrent' => $this->integer('max_concurrent'),
            'daily_cap' => $this->has('daily_cap') ? $this->integer('daily_cap') : TrialSettings::dailyCap(),
            'require_verified' => $this->boolean('require_verified'),
        ];
    }
}
