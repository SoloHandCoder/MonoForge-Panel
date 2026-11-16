<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Services\Billing\Revenue\RevenueRange;

class GetRevenueRequest extends AdminApiRequest
{
    /** @var list<string> */
    public const array RANGES = ['7', '30', '90', 'custom'];

    public function permissions(): array
    {
        return [Permissions::AdminBillingRead];
    }

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'range' => ['sometimes', 'string', 'in:'.implode(',', self::RANGES)],
            'from' => ['required_if:range,custom', 'date_format:Y-m-d'],
            'to' => ['required_if:range,custom', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $from = $this->input('from');
                $to = $this->input('to');
                if ($this->input('range') !== 'custom' || ! is_string($from) || ! is_string($to) || $validator->errors()->isNotEmpty()) {
                    return;
                }

                if (RevenueRange::between($from, $to)->days() > RevenueRange::MAX_DAYS) {
                    $validator->errors()->add('to', sprintf('A custom range can be at most %d days.', RevenueRange::MAX_DAYS));
                }
            },
        ];
    }

    public function range(): RevenueRange
    {
        $range = $this->validated('range');
        $from = $this->validated('from');
        $to = $this->validated('to');

        if ($range === 'custom' && is_string($from) && is_string($to)) {
            return RevenueRange::between($from, $to);
        }

        return RevenueRange::lastDays(is_string($range) && is_numeric($range) ? (int) $range : 30, CarbonImmutable::now());
    }
}
