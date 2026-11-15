<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Assistant;

use Pterodactyl\Models\Assistant\AiUsage;

class GetAssistantUsageRequest extends AdminAssistantRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];
    }

    public function period(): string
    {
        $period = $this->validated('period');

        return is_string($period) ? $period : AiUsage::currentPeriod();
    }
}
