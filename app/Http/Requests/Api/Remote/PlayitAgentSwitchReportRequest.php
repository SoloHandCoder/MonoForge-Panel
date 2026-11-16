<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Remote;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Pterodactyl\Models\Billing\PlayitAgentClaim;

/** How the node's playit switch script ended: the new agent runs, or the old one is back. */
class PlayitAgentSwitchReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'regex:/^[0-9a-f]{48}$/'],
            'outcome' => ['required', 'string', Rule::in([PlayitAgentClaim::REPORT_SWITCHED, PlayitAgentClaim::REPORT_ROLLED_BACK])],
        ];
    }
}
