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
            'crash_doctor_enabled' => ['sometimes', 'boolean'],
            'default_provider_id' => ['present', 'nullable', 'integer', Rule::exists(AiProvider::class, 'id')],
            'ratio' => ['required', 'integer', 'min:1', 'max:'.AssistantSettings::MAX_RATIO],
            'free_tokens_monthly' => ['required', 'integer', 'min:0', 'max:'.AssistantSettings::MAX_FREE_TOKENS],
            'warn_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'max_tool_iterations' => ['required', 'integer', 'min:1', 'max:'.AssistantSettings::MAX_TOOL_ITERATIONS],
            'context_tokens' => ['required', 'integer', 'min:'.AssistantSettings::MIN_CONTEXT_TOKENS, 'max:'.AssistantSettings::MAX_CONTEXT_TOKENS],
            'max_output_tokens' => ['required', 'integer', 'min:64', 'max:'.AssistantSettings::MAX_OUTPUT_TOKENS],
            'allow_everything_enabled' => ['sometimes', 'boolean'],
            'web_search_enabled' => ['sometimes', 'boolean'],
            'web_search_provider' => ['sometimes', 'string', Rule::in(AssistantSettings::SEARCH_PROVIDERS)],
            'web_search_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'clear_web_search_key' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The settings to store. The optional ones keep their value when left out, so older
     * clients that do not send them change nothing.
     *
     * @return array<string, bool|int|string|null>
     */
    public function settings(): array
    {
        return [
            'enabled' => $this->boolean('enabled'),
            'server_enabled' => $this->boolean('server_enabled'),
            'admin_enabled' => $this->boolean('admin_enabled'),
            // Optional, so older clients that do not send it keep the current value.
            'crash_doctor_enabled' => $this->has('crash_doctor_enabled') ? $this->boolean('crash_doctor_enabled') : AssistantSettings::crashDoctorEnabled(),
            'default_provider_id' => $this->filled('default_provider_id') ? $this->integer('default_provider_id') : null,
            'ratio' => $this->integer('ratio'),
            'free_tokens_monthly' => $this->integer('free_tokens_monthly'),
            'warn_percent' => $this->integer('warn_percent'),
            'max_tool_iterations' => $this->integer('max_tool_iterations'),
            'context_tokens' => $this->integer('context_tokens'),
            'max_output_tokens' => $this->integer('max_output_tokens'),
            'allow_everything_enabled' => $this->has('allow_everything_enabled') ? $this->boolean('allow_everything_enabled') : AssistantSettings::allowEverythingEnabled(),
            'web_search_enabled' => $this->has('web_search_enabled') ? $this->boolean('web_search_enabled') : AssistantSettings::webSearchEnabled(),
            'web_search_provider' => $this->has('web_search_provider') ? $this->string('web_search_provider')->toString() : AssistantSettings::webSearchProvider(),
        ];
    }

    /**
     * The search API key change: a new key, '' to remove it, or null to keep the stored one
     * (a blank key keeps it too, as keys are write-only).
     */
    public function webSearchKey(): ?string
    {
        if ($this->boolean('clear_web_search_key')) {
            return '';
        }

        $key = mb_trim($this->string('web_search_key')->toString());

        return $key === '' ? null : $key;
    }
}
