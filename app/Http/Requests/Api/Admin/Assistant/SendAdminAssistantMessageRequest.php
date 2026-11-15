<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Assistant;

use Pterodactyl\Http\Requests\Api\Client\Servers\Assistant\SendAssistantMessageRequest;

class SendAdminAssistantMessageRequest extends AdminAssistantRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:1', 'max:'.SendAssistantMessageRequest::MAX_MESSAGE_LENGTH],
        ];
    }

    public function message(): string
    {
        return mb_trim($this->string('message')->toString());
    }
}
