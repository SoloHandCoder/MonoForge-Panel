<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant;

use Pterodactyl\Exceptions\Assistant\AssistantUnavailableException;
use Pterodactyl\Models\Assistant\AiConversation;
use Pterodactyl\Models\Assistant\AiProvider;

/**
 * Which provider a conversation talks to: its own while that is enabled, else the default
 * provider from the settings, else the first enabled one.
 */
final class AssistantProviders
{
    /**
     * @throws AssistantUnavailableException when no provider is enabled
     */
    public static function resolve(?AiConversation $conversation = null): AiProvider
    {
        $provider = $conversation?->provider_id === null ? null : AiProvider::query()->whereKey($conversation->provider_id)->where('enabled', true)->first();

        if (! $provider instanceof AiProvider && AssistantSettings::defaultProviderId() !== null) {
            $provider = AiProvider::query()->whereKey(AssistantSettings::defaultProviderId())->where('enabled', true)->first();
        }

        $provider ??= AiProvider::query()->where('enabled', true)->orderBy('id')->first();

        throw_unless($provider instanceof AiProvider, AssistantUnavailableException::class, 'No AI provider is set up yet. Ask an administrator to add one.', 503);

        if ($conversation instanceof AiConversation && $conversation->provider_id !== $provider->id) {
            $conversation->forceFill(['provider_id' => $provider->id])->save();
            $conversation->setRelation('provider', $provider);
        }

        return $provider;
    }
}
