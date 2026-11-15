<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Assistant;

use Closure;
use Illuminate\Validation\Rule;
use Pterodactyl\Enum\AiProviderPreset;
use Pterodactyl\Models\Assistant\AiProvider;
use Pterodactyl\Services\Assistant\Chat\ProviderUrl;

class StoreAiProviderRequest extends AdminAssistantRequest
{
    public const int MAX_HEADERS = 20;

    /**
     * The key is write-only: send it to set it, null to remove it, or leave it out to keep it.
     * Extra header values are write-only too: extra_headers lists every header by name, with a
     * new value to set it, or null (or "") to keep the stored value. A header left out of the
     * list is removed, and leaving extra_headers out keeps them all.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:191'],
            'preset' => ['required', 'string', Rule::in(AiProviderPreset::values())],
            'base_url' => ['required', 'string', 'url:http,https', 'max:191', $this->allowedUrl(...)],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'model' => ['required', 'string', 'min:1', 'max:191'],
            'extra_headers' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_HEADERS, $this->validHeaders(...)],
            'context_window' => ['sometimes', 'integer', 'min:1024', 'max:10000000'],
            'supports_tools' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The validated attributes to store, over the provider's current ones when it is updated.
     *
     * @return array<string, mixed>
     */
    public function providerAttributes(?AiProvider $existing = null): array
    {
        $attributes = [];
        foreach (['name', 'preset', 'base_url', 'model'] as $key) {
            if ($this->has($key)) {
                $attributes[$key] = mb_trim($this->string($key)->toString());
            }
        }

        if ($this->has('api_key')) {
            $key = $this->input('api_key');
            $attributes['api_key'] = is_string($key) && mb_trim($key) !== '' ? mb_trim($key) : null;
        }

        if ($this->has('extra_headers')) {
            $attributes['extra_headers'] = $this->headers($existing);
        }

        if ($this->has('context_window')) {
            $attributes['context_window'] = $this->integer('context_window');
        }

        foreach (['supports_tools', 'enabled'] as $key) {
            if ($this->has($key)) {
                $attributes[$key] = $this->boolean($key);
            }
        }

        return $attributes;
    }

    /**
     * The headers to store: sent values replace stored ones, and a header sent without a value
     * keeps its stored value.
     *
     * @return array<string, string>|null
     */
    private function headers(?AiProvider $existing): ?array
    {
        $sent = $this->input('extra_headers');
        if (! is_array($sent) || $sent === []) {
            return null;
        }

        $stored = $existing->extra_headers ?? [];
        $headers = [];
        foreach ($sent as $name => $value) {
            $name = (string) $name;
            if (is_string($value) && $value !== '') {
                $headers[$name] = $value;
            } elseif (is_scalar($stored[$name] ?? null)) {
                $headers[$name] = (string) $stored[$name];
            }
        }

        return $headers === [] ? null : $headers;
    }

    /** The provider being updated, when there is one. */
    private function existingProvider(): ?AiProvider
    {
        $provider = $this->route('provider');

        return $provider instanceof AiProvider ? $provider : null;
    }

    private function allowedUrl(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! ProviderUrl::isAllowed($value)) {
            $fail('This address is not allowed for an AI provider.');
        }
    }

    private function validHeaders(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $name => $header) {
            if (! is_string($name) || preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/', $name) !== 1) {
                $fail('Header names may only contain letters, numbers and dashes.');

                return;
            }

            if (in_array(mb_strtolower($name), ['authorization', 'host', 'content-length', 'content-type', 'cookie'], true)) {
                $fail("The {$name} header cannot be set here; use the API key field for authentication.");

                return;
            }

            if ($header === null || $header === '') {
                if (! array_key_exists($name, $this->existingProvider()->extra_headers ?? [])) {
                    $fail("Enter a value for the {$name} header.");

                    return;
                }

                continue;
            }

            if (! is_string($header) || mb_strlen($header) > 1024 || preg_match('/[\r\n]/', $header) === 1) {
                $fail('Header values must be single-line text of at most 1024 characters.');

                return;
            }
        }
    }
}
