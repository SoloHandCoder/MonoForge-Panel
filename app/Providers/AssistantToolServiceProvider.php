<?php

declare(strict_types=1);

namespace Pterodactyl\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Pterodactyl\Services\Assistant\ToolRegistry;
use Pterodactyl\Services\Assistant\Tools\AbstractTool;
use Pterodactyl\Services\Assistant\Tools\Admin;
use Pterodactyl\Services\Assistant\Tools\Admin\AdjustTokensTool;
use Pterodactyl\Services\Assistant\Tools\Admin\CreateAnnouncementTool;
use Pterodactyl\Services\Assistant\Tools\Admin\ListServersTool;
use Pterodactyl\Services\Assistant\Tools\Admin\ListUsersTool;
use Pterodactyl\Services\Assistant\Tools\Admin\NodeUsageTool;
use Pterodactyl\Services\Assistant\Tools\Admin\SearchActivityTool;
use Pterodactyl\Services\Assistant\Tools\Admin\ServerOverviewTool;
use Pterodactyl\Services\Assistant\Tools\Admin\ServerSuspensionTool;
use Pterodactyl\Services\Assistant\Tools\Admin\ShopOverviewTool;
use Pterodactyl\Services\Assistant\Tools\Server;
use Pterodactyl\Services\Assistant\Tools\Server\CreateBackupTool;
use Pterodactyl\Services\Assistant\Tools\Server\DeleteFilesTool;
use Pterodactyl\Services\Assistant\Tools\Server\EditFileTool;
use Pterodactyl\Services\Assistant\Tools\Server\GetNetworkTool;
use Pterodactyl\Services\Assistant\Tools\Server\GetStartupTool;
use Pterodactyl\Services\Assistant\Tools\Server\ListBackupsTool;
use Pterodactyl\Services\Assistant\Tools\Server\ListFilesTool;
use Pterodactyl\Services\Assistant\Tools\Server\ListSchedulesTool;
use Pterodactyl\Services\Assistant\Tools\Server\PowerTool;
use Pterodactyl\Services\Assistant\Tools\Server\ReadConsoleTool;
use Pterodactyl\Services\Assistant\Tools\Server\ReadFileTool;
use Pterodactyl\Services\Assistant\Tools\Server\RenameFileTool;
use Pterodactyl\Services\Assistant\Tools\Server\RevealStartupVariableTool;
use Pterodactyl\Services\Assistant\Tools\Server\SearchFilesTool;
use Pterodactyl\Services\Assistant\Tools\Server\SendCommandTool;
use Pterodactyl\Services\Assistant\Tools\Server\ServerStatusTool;
use Pterodactyl\Services\Assistant\Tools\Server\UpdateStartupVariableTool;
use Pterodactyl\Services\Assistant\Tools\Server\WriteFileTool;

/**
 * Registers the assistant's tools with the tool registry once it is first resolved.
 */
class AssistantToolServiceProvider extends ServiceProvider
{
    /** @var list<class-string<Server\ServerTool>> */
    public const array SERVER_TOOLS = [
        ServerStatusTool::class,
        ReadConsoleTool::class,
        ListFilesTool::class,
        ReadFileTool::class,
        SearchFilesTool::class,
        ListBackupsTool::class,
        ListSchedulesTool::class,
        GetStartupTool::class,
        RevealStartupVariableTool::class,
        GetNetworkTool::class,
        WriteFileTool::class,
        EditFileTool::class,
        DeleteFilesTool::class,
        RenameFileTool::class,
        SendCommandTool::class,
        PowerTool::class,
        CreateBackupTool::class,
        UpdateStartupVariableTool::class,
    ];

    /** @var list<class-string<Admin\AdminTool>> */
    public const array ADMIN_TOOLS = [
        ListServersTool::class,
        ServerOverviewTool::class,
        ListUsersTool::class,
        NodeUsageTool::class,
        SearchActivityTool::class,
        ShopOverviewTool::class,
        CreateAnnouncementTool::class,
        AdjustTokensTool::class,
    ];

    public function register(): void
    {
        if (! $this->app->bound(ToolRegistry::class)) {
            $this->app->singleton(ToolRegistry::class);
        }

        $this->app->afterResolving(ToolRegistry::class, function (ToolRegistry $registry, Application $app): void {
            foreach (self::SERVER_TOOLS as $tool) {
                $registry->register(AbstractTool::SCOPE_SERVER, $app->make($tool));
            }

            foreach (self::ADMIN_TOOLS as $tool) {
                $registry->register(AbstractTool::SCOPE_ADMIN, $app->make($tool));
            }

            $registry->register(AbstractTool::SCOPE_ADMIN, $app->make(ServerSuspensionTool::class, ['suspend' => true]));
            $registry->register(AbstractTool::SCOPE_ADMIN, $app->make(ServerSuspensionTool::class, ['suspend' => false]));
        });
    }
}
