<?php

declare(strict_types=1);

namespace Pterodactyl\Providers;

use Illuminate\Support\ServiceProvider;
use Pterodactyl\Services\Assistant\ToolRegistry;

/**
 * The AI assistant. The registry is shared so tool providers (Task X) can register their
 * tools from their own boot() with resolve(ToolRegistry::class)->register(...).
 */
class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ToolRegistry::class);
    }
}
