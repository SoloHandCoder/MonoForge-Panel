<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Assistant;

class ApproveAdminToolCallRequest extends AdminAssistantRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'until_next_message' => ['sometimes', 'boolean'],
        ];
    }

    /** "Allow until my next message": also allow the turn's further confirmations. */
    public function untilNextMessage(): bool
    {
        return $this->boolean('until_next_message');
    }
}
