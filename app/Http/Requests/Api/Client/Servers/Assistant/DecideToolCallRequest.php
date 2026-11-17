<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Assistant;

/** Allowing or denying a tool call: the route also requires a panel session, never an API key. */
class DecideToolCallRequest extends AssistantRequest
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
