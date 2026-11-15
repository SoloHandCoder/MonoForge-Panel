<?php

declare(strict_types=1);

namespace Pterodactyl\Enum;

/**
 * A kind of OpenAI-compatible chat completions API the assistant can talk to, with the
 * base URL and model it starts from and the quirks the client has to handle.
 */
enum AiProviderPreset: string
{
    case Cline = 'cline';
    case ClinePass = 'clinepass';
    case Ollama = 'ollama';
    case OpenRouter = 'openrouter';
    case DeepSeek = 'deepseek';
    case OpenAi = 'openai';
    case Custom = 'custom';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $preset): string => $preset->value, self::cases());
    }

    /**
     * What the admin form prefills for each preset.
     *
     * @return list<array{preset: string, label: string, base_url: string, model: string, key_optional: bool}>
     */
    public static function catalog(): array
    {
        return array_map(fn (self $preset): array => [
            'preset' => $preset->value,
            'label' => $preset->label(),
            'base_url' => $preset->baseUrl(),
            'model' => $preset->defaultModel(),
            'key_optional' => $preset->keyOptional(),
        ], self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Cline => 'Cline',
            self::ClinePass => 'ClinePass',
            self::Ollama => 'Ollama',
            self::OpenRouter => 'OpenRouter',
            self::DeepSeek => 'DeepSeek',
            self::OpenAi => 'OpenAI',
            self::Custom => 'Custom (OpenAI-compatible)',
        };
    }

    public function baseUrl(): string
    {
        return match ($this) {
            self::Cline, self::ClinePass => 'https://api.cline.bot/api/v1',
            self::Ollama => 'http://127.0.0.1:11434/v1',
            self::OpenRouter => 'https://openrouter.ai/api/v1',
            self::DeepSeek => 'https://api.deepseek.com',
            self::OpenAi => 'https://api.openai.com/v1',
            self::Custom => '',
        };
    }

    public function defaultModel(): string
    {
        return match ($this) {
            self::Cline => 'deepseek/deepseek-chat',
            self::ClinePass => 'cline-pass/deepseek-v4.1-flash',
            self::Ollama => 'llama3.1:8b',
            self::OpenRouter => 'deepseek/deepseek-chat',
            self::DeepSeek => 'deepseek-flash',
            self::OpenAi => 'gpt-4.1-mini',
            self::Custom => '',
        };
    }

    /** Whether the provider works without an API key (a local Ollama does). */
    public function keyOptional(): bool
    {
        return $this === self::Ollama || $this === self::Custom;
    }

    /**
     * Headers this kind of provider always needs. Cline answers non-streaming requests in a
     * legacy `{success, data}` wrapper unless it is asked for the OpenAI format.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return match ($this) {
            self::Cline, self::ClinePass => ['X-Response-Format' => 'openai', 'X-Title' => 'MonoForge Panel'],
            self::OpenRouter => ['X-Title' => 'MonoForge Panel'],
            default => [],
        };
    }
}
