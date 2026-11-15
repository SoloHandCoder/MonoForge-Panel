<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Support\Assistant;

use Pterodactyl\Enum\AiProviderPreset;
use Pterodactyl\Models\Assistant\AiProvider;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolRegistry;

/** Builders for the assistant tests: a provider, OpenAI-shaped answers and the example tools. */
final class FakeAi
{
    public const string BASE_URL = 'https://ai.example.test/v1';

    public const string URL = self::BASE_URL.'/chat/completions';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function provider(array $attributes = []): AiProvider
    {
        return AiProvider::query()->create([
            'name' => 'Test provider',
            'preset' => AiProviderPreset::Custom,
            'base_url' => self::BASE_URL,
            'api_key' => 'sk-test-secret-key',
            'model' => 'test-model',
            ...$attributes,
        ]);
    }

    /** Turns the assistant on with a provider. */
    public static function enable(int $freeTokens = 50000, int $ratio = 1000): AiProvider
    {
        config()->set('monoforge.ai.enabled', true);
        config()->set('monoforge.ai.server_enabled', true);
        config()->set('monoforge.ai.admin_enabled', true);
        config()->set('monoforge.ai.free_tokens_monthly', $freeTokens);
        config()->set('monoforge.ai.ratio', $ratio);

        return self::provider();
    }

    /**
     * @return array{echo: EchoTool, confirm: ConfirmTool}
     */
    public static function tools(string $scope = ToolContext::SCOPE_SERVER): array
    {
        $echo = new EchoTool;
        $confirm = new ConfirmTool;
        $registry = resolve(ToolRegistry::class);
        $registry->register($scope, $echo);
        $registry->register($scope, $confirm);

        return ['echo' => $echo, 'confirm' => $confirm];
    }

    /**
     * @return array<string, mixed>
     */
    public static function text(string $content, int $prompt = 100, int $completion = 20): array
    {
        return [
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'total_tokens' => $prompt + $completion],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function toolCall(string $name, array $arguments, string $id = 'call_1', int $prompt = 100, int $completion = 20): array
    {
        return [
            'id' => 'chatcmpl-2',
            'object' => 'chat.completion',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => $id,
                        'type' => 'function',
                        'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'total_tokens' => $prompt + $completion],
        ];
    }
}
