<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client;

/** Any signed-in user may pause or resume their own assistant. */
class UpdateAssistantPauseRequest extends ClientApiRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'paused' => ['required', 'boolean'],
        ];
    }

    public function paused(): bool
    {
        return $this->boolean('paused');
    }
}
