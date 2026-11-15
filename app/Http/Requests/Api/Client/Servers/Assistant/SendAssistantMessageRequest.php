<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Assistant;

class SendAssistantMessageRequest extends AssistantRequest
{
    public const int MAX_MESSAGE_LENGTH = 8000;

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:1', 'max:'.self::MAX_MESSAGE_LENGTH],
        ];
    }

    public function message(): string
    {
        return mb_trim($this->string('message')->toString());
    }
}
