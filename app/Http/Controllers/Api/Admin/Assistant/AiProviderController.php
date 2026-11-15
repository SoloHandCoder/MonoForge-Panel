<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Assistant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Enum\AiProviderPreset;
use Pterodactyl\Exceptions\Assistant\ChatProviderException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Assistant\AdminAssistantRequest;
use Pterodactyl\Http\Requests\Api\Admin\Assistant\StoreAiProviderRequest;
use Pterodactyl\Http\Requests\Api\Admin\Assistant\UpdateAiProviderRequest;
use Pterodactyl\Models\Assistant\AiProvider;
use Pterodactyl\Services\Assistant\AssistantSettings;
use Pterodactyl\Services\Assistant\Chat\ChatClient;
use Throwable;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('AI Assistant', 'The admin AI assistant, its providers, settings and usage. Root administrators only, from a panel session.')]
class AiProviderController extends AdminApiController
{
    private const array PROVIDER_EXAMPLE = [
        'id' => 1,
        'name' => 'ClinePass DeepSeek',
        'preset' => 'clinepass',
        'base_url' => 'https://api.cline.bot/api/v1',
        'has_key' => true,
        'model' => 'cline-pass/deepseek-v4.1-flash',
        'extra_headers' => [['name' => 'X-Title', 'has_value' => true]],
        'context_window' => 32000,
        'supports_tools' => true,
        'enabled' => true,
        'created_at' => '2026-11-18T12:00:00+00:00',
        'updated_at' => '2026-11-18T12:00:00+00:00',
    ];

    private const array LIST_EXAMPLE = [
        'data' => [self::PROVIDER_EXAMPLE],
        'meta' => [
            'default_provider_id' => 1,
            'presets' => [[
                'preset' => 'clinepass',
                'label' => 'ClinePass',
                'base_url' => 'https://api.cline.bot/api/v1',
                'model' => 'cline-pass/deepseek-v4.1-flash',
                'key_optional' => false,
            ]],
        ],
    ];

    private const array TEST_EXAMPLE = [
        'ok' => true,
        'latency_ms' => 812,
        'error' => null,
        'reply' => 'OK',
    ];

    #[Endpoint('List AI providers', 'Returns the configured AI providers (API keys are never returned, only has_key), the default provider and the presets the form prefills from.')]
    #[ScribeResponse(self::LIST_EXAMPLE, description: 'Providers returned.')]
    public function index(AdminAssistantRequest $request): JsonResponse
    {
        return new JsonResponse([
            'data' => AiProvider::query()->orderBy('id')->get()->map(fn (AiProvider $provider): array => $provider->toApiArray())->values()->all(),
            'meta' => [
                'default_provider_id' => AssistantSettings::defaultProviderId(),
                'presets' => AiProviderPreset::catalog(),
            ],
        ]);
    }

    #[Endpoint('Create an AI provider', 'Adds an OpenAI-compatible provider. The API key is write-only and stored encrypted. Cloud metadata addresses are refused as base URL.')]
    #[ScribeResponse(self::PROVIDER_EXAMPLE, status: 201, description: 'Provider created.')]
    public function store(StoreAiProviderRequest $request): JsonResponse
    {
        $provider = AiProvider::query()->create($request->providerAttributes());

        Activity::event('admin:assistant.provider.create')->property('name', $provider->name)->log();

        return new JsonResponse($provider->toApiArray(), Response::HTTP_CREATED);
    }

    #[Endpoint('Update an AI provider', 'Changes the fields that are sent. api_key: a new key replaces the stored one, null removes it, leaving it out keeps it. extra_headers: list every header by name with a new value, or with null to keep its stored value; headers left out are removed. Header values are never returned.')]
    #[UrlParam('provider_id', 'integer', 'The AI provider ID.', example: 1)]
    #[ScribeResponse(self::PROVIDER_EXAMPLE, description: 'Provider updated.')]
    public function update(UpdateAiProviderRequest $request, AiProvider $provider): JsonResponse
    {
        $provider->fill($request->providerAttributes($provider))->save();

        Activity::event('admin:assistant.provider.update')->property('name', $provider->name)->log();

        return new JsonResponse($provider->toApiArray());
    }

    #[Endpoint('Delete an AI provider', 'Deletes the provider. Its conversations continue with the default provider.')]
    #[UrlParam('provider_id', 'integer', 'The AI provider ID.', example: 1)]
    #[ScribeResponse(status: 204, description: 'Provider deleted.')]
    public function destroy(AdminAssistantRequest $request, AiProvider $provider): Response
    {
        $name = $provider->name;
        $provider->delete();

        if (AssistantSettings::defaultProviderId() === $provider->id) {
            AssistantSettings::store(['default_provider_id' => null]);
        }

        Activity::event('admin:assistant.provider.delete')->property('name', $name)->log();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Endpoint('Test an AI provider', 'Sends a short prompt to the provider and reports whether it answered, how long it took and, on failure, a short error. Nothing is charged.')]
    #[UrlParam('provider_id', 'integer', 'The AI provider ID.', example: 1)]
    #[ScribeResponse(self::TEST_EXAMPLE, description: 'Test finished.')]
    public function test(AdminAssistantRequest $request, AiProvider $provider, ChatClient $client): JsonResponse
    {
        $started = hrtime(true);

        try {
            $response = $client->complete($provider, [
                ['role' => 'user', 'content' => 'Reply with the single word OK.'],
            ], [], 16);
            $result = ['ok' => true, 'error' => null, 'reply' => mb_substr(mb_trim($response->content ?? ''), 0, 100)];
        } catch (ChatProviderException $exception) {
            $result = ['ok' => false, 'error' => $exception->getMessage(), 'reply' => null];
        } catch (Throwable $exception) {
            Log::warning('AI provider test failed.', ['provider' => $provider->id, 'exception' => $exception::class]);
            $result = ['ok' => false, 'error' => 'The test failed unexpectedly.', 'reply' => null];
        }

        return new JsonResponse([
            'ok' => $result['ok'],
            'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'error' => $result['error'],
            'reply' => $result['reply'],
        ]);
    }
}
