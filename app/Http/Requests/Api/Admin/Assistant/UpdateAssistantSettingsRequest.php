<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Assistant;

use Illuminate\Validation\Rule;
use Pterodactyl\Models\Assistant\AiProvider;
use Pterodactyl\Services\Assistant\AssistantSettings;

class UpdateAssistantSettingsRequest extends AdminAssistantRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'server_enabled' => ['required', 'boolean'],
            'admin_enabled' => ['required', 'boolean'],
            'default_provider_id' => ['present', 'nullable', 'integer', Rule::exists(AiProvider::class, 'id')],
            'ratio' => ['required', 'integer', 'min:1', 'max:'.AssistantSettings::MAX_RATIO],
            'free_tokens_monthly' => ['required', 'integer', 'min:0', 'max:'.AssistantSettings::MAX_FREE_TOKENS],
            'warn_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'max_tool_iterations' => ['required', 'integer', 'min:1', 'max:'.AssistantSettings::MAX_TOOL_ITERATIONS],
            'context_tokens' => ['required', 'integer', 'min:'.AssistantSettings::MIN_CONTEXT_TOKENS, 'max:'.AssistantSettings::MAX_CONTEXT_TOKENS],
            'max_output_tokens' => ['required', 'integer', 'min:64', 'max:'.AssistantSettings::MAX_OUTPUT_TOKENS],
        ];
    }

    /**
     * @return array<string, bool|int|null>
     */
    public function settings(): array
    {
        return [
            'enabled' => $this->boolean('enabled'),
            'server_enabled' => $this->boolean('server_enabled'),
            'admin_enabled' => $this->boolean('admin_enabled'),
            'default_provider_id' => $this->filled('default_provider_id') ? $this->integer('default_provider_id') : null,
            'ratio' => $this->integer('ratio'),
            'free_tokens_monthly' => $this->integer('free_tokens_monthly'),
            'warn_percent' => $this->integer('warn_percent'),
            'max_tool_iterations' => $this->integer('max_tool_iterations'),
            'context_tokens' => $this->integer('context_tokens'),
            'max_output_tokens' => $this->integer('max_output_tokens'),
        ];
    }
}
