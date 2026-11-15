<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\CrashDoctor;

use Pterodactyl\Http\Requests\Api\Client\Servers\Assistant\AssistantRequest;

/** Starting the crash doctor needs `assistant.use`, as any assistant conversation. */
class DiagnoseCrashRequest extends AssistantRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'crash_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function crashId(): ?int
    {
        return $this->filled('crash_id') ? $this->integer('crash_id') : null;
    }
}
