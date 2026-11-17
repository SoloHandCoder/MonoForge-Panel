<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Assistant;

use Illuminate\Validation\Rule;
use Pterodactyl\Models\Assistant\AiConversation;

/** Changing the approval mode: the route also requires a panel session, never an API key. */
class SetApprovalModeRequest extends AssistantRequest
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

    /** Whether the user confirmed the "Allow everything" warning. */
    public function confirmed(): bool
    {
        return $this->boolean('confirmed');
    }
}
