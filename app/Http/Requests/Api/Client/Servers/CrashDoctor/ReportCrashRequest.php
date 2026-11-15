<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\CrashDoctor;

use Illuminate\Validation\Rule;
use Pterodactyl\Http\Requests\Api\Client\Servers\Assistant\AssistantRequest;
use Pterodactyl\Models\ServerCrash;

/** The console saw the server go offline without stopping first; needs `assistant.use`. */
class ReportCrashRequest extends AssistantRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in([ServerCrash::KIND_CRASHED, ServerCrash::KIND_START_FAILED])],
        ];
    }

    public function kind(): string
    {
        return $this->string('kind')->toString();
    }
}
