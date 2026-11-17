<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Assistant;

use Illuminate\Validation\Rule;
use Pterodactyl\Models\Assistant\AiConversation;

class SetAdminApprovalModeRequest extends AdminAssistantRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(AiConversation::APPROVAL_MODES)],
            'confirmed' => ['sometimes', 'boolean'],
        ];
    }

    public function mode(): string
    {
        return $this->string('mode')->toString();
    }

    /** Whether the admin confirmed the "Allow everything" warning. */
    public function confirmed(): bool
    {
        return $this->boolean('confirmed');
    }
}
