<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Remote;

use Illuminate\Foundation\Http\FormRequest;

/** The one-time token the administrator pasted into the node's playit switch script. */
class PlayitAgentSecretRequest extends FormRequest
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
        ];
    }
}
