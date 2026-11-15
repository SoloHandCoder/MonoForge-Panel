<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pterodactyl\Enum\LegalDocument;
use Pterodactyl\Http\Controllers\Api\Admin\ActivityController;
use Pterodactyl\Http\Controllers\Api\Admin\ActivityFilterController;
use Pterodactyl\Http\Controllers\Api\Admin\Announcements\AnnouncementController;
use Pterodactyl\Http\Controllers\Api\Admin\ApiKeys\ApiKeyController;
use Pterodactyl\Http\Controllers\Api\Admin\Assistant\AdminConversationController;
use Pterodactyl\Http\Controllers\Api\Admin\Assistant\AiProviderController;
use Pterodactyl\Http\Controllers\Api\Admin\Assistant\AssistantSettingsController;
use Pterodactyl\Http\Controllers\Api\Admin\Assistant\AssistantUsageController;
use Pterodactyl\Http\Controllers\Api\Admin\DatabaseHosts\DatabaseHostController;
use Pterodactyl\Http\Controllers\Api\Admin\Eggs\EggCatalogController;
use Pterodactyl\Http\Controllers\Api\Admin\Eggs\EggController;
use Pterodactyl\Http\Controllers\Api\Admin\Eggs\EggScriptController;
use Pterodactyl\Http\Controllers\Api\Admin\Eggs\EggVariableController;
use Pterodactyl\Http\Controllers\Api\Admin\Eggs\ExportEggController;
use Pterodactyl\Http\Controllers\Api\Admin\Eggs\ImportCatalogEggController;
use Pterodactyl\Http\Controllers\Api\Admin\Eggs\ImportEggController;
use Pterodactyl\Http\Controllers\Api\Admin\Eggs\ReorderVariablesController;
use Pterodactyl\Http\Controllers\Api\Admin\Eggs\UpdateImportEggController;
use Pterodactyl\Http\Controllers\Api\Admin\Extensions\ExtensionController;
use Pterodactyl\Http\Controllers\Api\Admin\Insights\StorageController;
use Pterodactyl\Http\Controllers\Api\Admin\Insights\UsageController;
use Pterodactyl\Http\Controllers\Api\Admin\LanguagesController;
use Pterodactyl\Http\Controllers\Api\Admin\Locations\EligibleNodesController;
use Pterodactyl\Http\Controllers\Api\Admin\Locations\LocationController;
use Pterodactyl\Http\Controllers\Api\Admin\Mounts\AttachEggsController;
use Pterodactyl\Http\Controllers\Api\Admin\Mounts\AttachNodesController;
use Pterodactyl\Http\Controllers\Api\Admin\Mounts\DetachEggController;
use Pterodactyl\Http\Controllers\Api\Admin\Mounts\DetachNodeController;
use Pterodactyl\Http\Controllers\Api\Admin\Mounts\MountController;
use Pterodactyl\Http\Controllers\Api\Admin\Nodes\AllocationController;
use Pterodactyl\Http\Controllers\Api\Admin\Nodes\AvailableAllocationsController;
use Pterodactyl\Http\Controllers\Api\Admin\Nodes\BulkDeleteAllocationsController;
use Pterodactyl\Http\Controllers\Api\Admin\Nodes\ConfigurationController;
use Pterodactyl\Http\Controllers\Api\Admin\Nodes\DeleteAllocationBlockController;
use Pterodactyl\Http\Controllers\Api\Admin\Nodes\DeployTokenController;
use Pterodactyl\Http\Controllers\Api\Admin\Nodes\NodeController;
use Pterodactyl\Http\Controllers\Api\Admin\Nodes\PlayitController;
use Pterodactyl\Http\Controllers\Api\Admin\Nodes\SystemInformationController;
use Pterodactyl\Http\Controllers\Api\Admin\Nodes\UniqueIpsController;
use Pterodactyl\Http\Controllers\Api\Admin\Nodes\UtilizationController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\BuildController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\DatabaseController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\DetailsController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\ExternalController as ServerExternalController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\RebuildController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\ReinstallController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\RotateDatabasePasswordController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\ServerBackupController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\ServerController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\ServerMountController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\StartupController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\SubscriptionController as ServerSubscriptionController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\SuspensionController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\ToggleBackupController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\ToggleInstallController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\TransferController;
use Pterodactyl\Http\Controllers\Api\Admin\Servers\TransferProgressController;
use Pterodactyl\Http\Controllers\Api\Admin\Settings\AdvancedController;
use Pterodactyl\Http\Controllers\Api\Admin\Settings\GeneralController;
use Pterodactyl\Http\Controllers\Api\Admin\Settings\LegalController;
use Pterodactyl\Http\Controllers\Api\Admin\Settings\LoginProvidersController;
use Pterodactyl\Http\Controllers\Api\Admin\Settings\MailController;
use Pterodactyl\Http\Controllers\Api\Admin\Settings\PluginSettingsController;
use Pterodactyl\Http\Controllers\Api\Admin\Settings\RegistrationController;
use Pterodactyl\Http\Controllers\Api\Admin\Settings\SettingsController;
use Pterodactyl\Http\Controllers\Api\Admin\Settings\StatusPageController;
use Pterodactyl\Http\Controllers\Api\Admin\Settings\TestMailController;
use Pterodactyl\Http\Controllers\Api\Admin\Shop\ShopAddonController;
use Pterodactyl\Http\Controllers\Api\Admin\Shop\ShopCategoryController;
use Pterodactyl\Http\Controllers\Api\Admin\Shop\ShopMultiplierController;
use Pterodactyl\Http\Controllers\Api\Admin\Shop\ShopNodeController;
use Pterodactyl\Http\Controllers\Api\Admin\Shop\ShopPeriodController;
use Pterodactyl\Http\Controllers\Api\Admin\Shop\ShopPlanController;
use Pterodactyl\Http\Controllers\Api\Admin\Shop\ShopSettingsController;
use Pterodactyl\Http\Controllers\Api\Admin\Shop\TebexPaymentController;
use Pterodactyl\Http\Controllers\Api\Admin\Shop\TebexSettingsController;
use Pterodactyl\Http\Controllers\Api\Admin\Shop\TokenPackController;
use Pterodactyl\Http\Controllers\Api\Admin\Tags\TagController;
use Pterodactyl\Http\Controllers\Api\Admin\Themes\ThemeController;
use Pterodactyl\Http\Controllers\Api\Admin\Tunnels\TunnelController;
use Pterodactyl\Http\Controllers\Api\Admin\Tunnels\TunnelSettingsController;
use Pterodactyl\Http\Controllers\Api\Admin\Updates\UpdateController;
use Pterodactyl\Http\Controllers\Api\Admin\Users\DisableTwoFactorController;
use Pterodactyl\Http\Controllers\Api\Admin\Users\ExternalController as UserExternalController;
use Pterodactyl\Http\Controllers\Api\Admin\Users\TokenController as UserTokenController;
use Pterodactyl\Http\Controllers\Api\Admin\Users\UserController;
use Pterodactyl\Http\Controllers\Api\Admin\VersionController;
use Pterodactyl\Http\Middleware\Api\Admin\RequireSessionAuthentication;

// Admin API (/api/admin), gated on the global root administrator flag.

Route::get('/languages', LanguagesController::class)->name('api.admin.languages');

Route::get('/activity', ActivityController::class)->name('api.admin.activity');
Route::get('/activity/filters', ActivityFilterController::class)->name('api.admin.activity.filters');

/*
|--------------------------------------------------------------------------
| Announcement Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/announcements
|
*/
Route::prefix('/announcements')->name('api.admin.announcements')->group(function (): void {
    Route::get('/', [AnnouncementController::class, 'index']);
    Route::get('/{announcement:id}', [AnnouncementController::class, 'show'])->name('.view');

    Route::post('/', [AnnouncementController::class, 'store'])->name('.store');
    Route::put('/{announcement:id}', [AnnouncementController::class, 'update'])->name('.update');

    Route::delete('/{announcement:id}', [AnnouncementController::class, 'destroy'])->name('.delete');
});

/*
|--------------------------------------------------------------------------
| Shop Routes (phase 3)
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/shop
|
| Catalog, pricing and limits, plus Tebex settings, token packs and payments.
|
*/
Route::prefix('/shop')->name('api.admin.shop')->group(function (): void {
    Route::get('/settings', [ShopSettingsController::class, 'index'])->name('.settings');
    Route::put('/settings', [ShopSettingsController::class, 'update'])->name('.settings.update');
    Route::get('/options', [ShopSettingsController::class, 'options'])->name('.options');

    Route::get('/categories', [ShopCategoryController::class, 'index'])->name('.categories');
    Route::post('/categories', [ShopCategoryController::class, 'store'])->name('.categories.store');
    Route::put('/categories/order', [ShopCategoryController::class, 'reorder'])->name('.categories.reorder');
    Route::put('/categories/{category:id}', [ShopCategoryController::class, 'update'])->name('.categories.update');
    Route::delete('/categories/{category:id}', [ShopCategoryController::class, 'destroy'])->name('.categories.delete');

    Route::get('/plans', [ShopPlanController::class, 'index'])->name('.plans');
    Route::get('/plans/{plan:id}', [ShopPlanController::class, 'show'])->name('.plans.view');
    Route::post('/plans', [ShopPlanController::class, 'store'])->name('.plans.store');
    Route::put('/plans/{plan:id}', [ShopPlanController::class, 'update'])->name('.plans.update');
    Route::delete('/plans/{plan:id}', [ShopPlanController::class, 'destroy'])->name('.plans.delete');

    Route::get('/periods', [ShopPeriodController::class, 'index'])->name('.periods');
    Route::post('/periods', [ShopPeriodController::class, 'store'])->name('.periods.store');
    Route::put('/periods/{period:id}', [ShopPeriodController::class, 'update'])->name('.periods.update');
    Route::delete('/periods/{period:id}', [ShopPeriodController::class, 'destroy'])->name('.periods.delete');

    Route::get('/multipliers', [ShopMultiplierController::class, 'index'])->name('.multipliers');
    Route::post('/multipliers', [ShopMultiplierController::class, 'store'])->name('.multipliers.store');
    Route::put('/multipliers/{multiplier:id}', [ShopMultiplierController::class, 'update'])->name('.multipliers.update');
    Route::delete('/multipliers/{multiplier:id}', [ShopMultiplierController::class, 'destroy'])->name('.multipliers.delete');

    Route::get('/nodes', [ShopNodeController::class, 'index'])->name('.nodes');
    Route::put('/nodes/{node:id}', [ShopNodeController::class, 'update'])->name('.nodes.update');

    Route::get('/addons', [ShopAddonController::class, 'index'])->name('.addons');
    Route::post('/addons', [ShopAddonController::class, 'store'])->name('.addons.store');
    Route::put('/addons/{addon:id}', [ShopAddonController::class, 'update'])->name('.addons.update');
    Route::delete('/addons/{addon:id}', [ShopAddonController::class, 'destroy'])->name('.addons.delete');

    Route::get('/tebex', [TebexSettingsController::class, 'index'])->name('.tebex');
    Route::put('/tebex', [TebexSettingsController::class, 'update'])->name('.tebex.update');
    Route::get('/tebex/payments', [TebexPaymentController::class, 'index'])->name('.tebex.payments');
    // Crediting a flagged payment mints tokens: a browser session only, never an API key.
    Route::post('/tebex/payments/{payment:id}/approve', [TebexPaymentController::class, 'approve'])->middleware(RequireSessionAuthentication::class)->name('.tebex.payments.approve');

    Route::get('/token-packs', [TokenPackController::class, 'index'])->name('.token-packs');
    Route::post('/token-packs', [TokenPackController::class, 'store'])->name('.token-packs.store');
    Route::put('/token-packs/{tokenPack:id}', [TokenPackController::class, 'update'])->name('.token-packs.update');
    Route::delete('/token-packs/{tokenPack:id}', [TokenPackController::class, 'destroy'])->name('.token-packs.delete');
});

/*
|--------------------------------------------------------------------------
| AI Assistant Routes (phase 4)
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/assistant
|
| The admin chat, providers (keys are write-only), settings and usage. Panel sessions only.
|
*/
Route::prefix('/assistant')->name('api.admin.assistant')->middleware(RequireSessionAuthentication::class)->group(function (): void {
    Route::get('/conversations', [AdminConversationController::class, 'index'])->name('.conversations')->withoutScopedBindings();
    Route::post('/conversations', [AdminConversationController::class, 'store'])->middleware('throttle:20,1')->name('.conversations.store')->withoutScopedBindings();
    Route::get('/conversations/{conversation}', [AdminConversationController::class, 'show'])->name('.conversations.view')->withoutScopedBindings();
    Route::post('/conversations/{conversation}/messages', [AdminConversationController::class, 'message'])->middleware('throttle:30,1')->name('.conversations.message')->withoutScopedBindings();
    Route::post('/conversations/{conversation}/tool-calls/{toolCall}/approve', [AdminConversationController::class, 'approve'])->name('.conversations.approve')->withoutScopedBindings();
    Route::post('/conversations/{conversation}/tool-calls/{toolCall}/deny', [AdminConversationController::class, 'deny'])->name('.conversations.deny')->withoutScopedBindings();
    Route::delete('/conversations/{conversation}', [AdminConversationController::class, 'destroy'])->name('.conversations.delete')->withoutScopedBindings();

    Route::get('/providers', [AiProviderController::class, 'index'])->name('.providers');
    Route::post('/providers', [AiProviderController::class, 'store'])->name('.providers.store');
    Route::put('/providers/{provider:id}', [AiProviderController::class, 'update'])->name('.providers.update');
    Route::delete('/providers/{provider:id}', [AiProviderController::class, 'destroy'])->name('.providers.delete');
    Route::post('/providers/{provider:id}/test', [AiProviderController::class, 'test'])->middleware('throttle:10,1')->name('.providers.test');

    Route::get('/settings', [AssistantSettingsController::class, 'index'])->name('.settings');
    Route::put('/settings', [AssistantSettingsController::class, 'update'])->name('.settings.update');

    Route::get('/usage', AssistantUsageController::class)->name('.usage');
});

/*
|--------------------------------------------------------------------------
| Extension Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/extensions
|
*/
Route::prefix('/extensions')->name('api.admin.extensions')->group(function (): void {
    Route::get('/', [ExtensionController::class, 'index']);
    Route::get('/forms', [ExtensionController::class, 'forms'])->name('.forms');
    Route::get('/{extension}/settings', [ExtensionController::class, 'settings'])->name('.settings');
    Route::get('/{extension}/icon', [ExtensionController::class, 'icon'])->name('.icon');

    Route::middleware(RequireSessionAuthentication::class)->group(function (): void {
        Route::post('/', [ExtensionController::class, 'store'])->name('.install');
        Route::patch('/{extension}/settings', [ExtensionController::class, 'updateSettings'])->name('.settings.update');
        Route::post('/{extension}/settings/{input}/file', [ExtensionController::class, 'uploadSettingFile'])->name('.settings.file');
        Route::delete('/{extension}/settings/{input}/file', [ExtensionController::class, 'clearSettingFile'])->name('.settings.file.clear');
        Route::post('/{extension}/enable', [ExtensionController::class, 'enable'])->name('.enable');
        Route::post('/{extension}/disable', [ExtensionController::class, 'disable'])->name('.disable');
        Route::delete('/{extension}', [ExtensionController::class, 'destroy'])->name('.delete');
    });
});

/*
|--------------------------------------------------------------------------
| Theme Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/themes
|
*/
Route::prefix('/themes')->name('api.admin.themes')->group(function (): void {
    Route::get('/', [ThemeController::class, 'index']);
    Route::get('/{theme}/settings', [ThemeController::class, 'settings'])->name('.settings');

    Route::middleware(RequireSessionAuthentication::class)->group(function (): void {
        Route::delete('/preview', [ThemeController::class, 'stopPreview'])->name('.preview.stop');
        Route::post('/{theme}/activate', [ThemeController::class, 'activate'])->name('.activate');
        Route::post('/{theme}/preview', [ThemeController::class, 'preview'])->name('.preview');
        Route::patch('/{theme}/settings', [ThemeController::class, 'updateSettings'])->name('.settings.update');
        Route::post('/{theme}/settings/{setting}/image', [ThemeController::class, 'uploadImage'])->name('.settings.image');
        Route::delete('/{theme}/settings/{setting}/image', [ThemeController::class, 'clearImage'])->name('.settings.image.clear');
    });
});

/*
|--------------------------------------------------------------------------
| User Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/users
|
*/
Route::prefix('/users')->name('api.admin.users')->group(function (): void {
    Route::get('/', [UserController::class, 'index']);
    // `/external/{external_id}` before `/{user:id}` so the literal is not bound as an id.
    Route::get('/external/{external_id}', UserExternalController::class)->name('.external');
    Route::get('/{user:id}', [UserController::class, 'show'])->name('.view');
    Route::get('/{user:id}/tokens', [UserTokenController::class, 'index'])->name('.tokens');

    Route::post('/', [UserController::class, 'store'])->name('.store');
    Route::post('/{user:id}/disable-2fa', DisableTwoFactorController::class)->name('.disable-2fa');
    // Tokens are money: a browser session only, never an API key.
    Route::post('/{user:id}/tokens', [UserTokenController::class, 'store'])->middleware(RequireSessionAuthentication::class)->name('.tokens.adjust');
    Route::put('/{user:id}', [UserController::class, 'update'])->name('.update');

    Route::delete('/{user:id}', [UserController::class, 'destroy'])->name('.delete');
});

/*
|--------------------------------------------------------------------------
| Node Action Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/nodes/{node}
|
*/
Route::prefix('/nodes/{node:id}')->name('api.admin.nodes')->group(function (): void {
    Route::get('/system-information', SystemInformationController::class)->name('.system-information');
    Route::post('/deploy-token', DeployTokenController::class)->middleware(RequireSessionAuthentication::class)->name('.deploy-token');
    Route::get('/utilization', UtilizationController::class)->name('.utilization');
    Route::get('/playit', [PlayitController::class, 'show'])->name('.playit');
    Route::put('/playit', [PlayitController::class, 'update'])->name('.playit.update');
    Route::post('/playit/test', [PlayitController::class, 'test'])->name('.playit.test');
});

/*
|--------------------------------------------------------------------------
| Tunnel Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/tunnels
|
*/
Route::prefix('/tunnels')->name('api.admin.tunnels')->group(function (): void {
    Route::get('/', [TunnelController::class, 'index']);
    Route::post('/', [TunnelController::class, 'store'])->name('.store');
    // Links tunnels made by hand on playit.gg to servers: a browser session only, never an API key.
    Route::post('/import', [TunnelController::class, 'import'])->middleware(RequireSessionAuthentication::class)->name('.import');
    // `/settings` before `/{tunnel}` so the literal is not bound as a tunnel.
    Route::get('/settings', [TunnelSettingsController::class, 'show'])->name('.settings');
    Route::put('/settings', [TunnelSettingsController::class, 'update'])->name('.settings.update');
    Route::post('/{tunnel:id}/retry', [TunnelController::class, 'retry'])->name('.retry');
    Route::post('/{tunnel:id}/recreate', [TunnelController::class, 'recreate'])->name('.recreate');
});

/*
|--------------------------------------------------------------------------
| Insights Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/insights
|
*/
Route::prefix('/insights')->name('api.admin.insights')->group(function (): void {
    Route::get('/usage', UsageController::class)->name('.usage');
    Route::get('/storage', StorageController::class)->name('.storage');
});

/*
|--------------------------------------------------------------------------
| Server Mount Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/servers/{server}/mounts
|
*/
Route::prefix('/servers/{server:admin_identifier}/mounts')->name('api.admin.servers.mounts')->group(function (): void {
    Route::get('/', [ServerMountController::class, 'index']);
    Route::post('/', [ServerMountController::class, 'store'])->name('.store');
    Route::delete('/{mount:id}', [ServerMountController::class, 'destroy'])->name('.delete')->withoutScopedBindings();
});

/*
|--------------------------------------------------------------------------
| Server Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/servers
|
*/
Route::prefix('/servers')->name('api.admin.servers')->group(function (): void {
    Route::get('/', [ServerController::class, 'index']);
    // `/external/{external_id}` before `/{server}` so the literal is not bound as a server.
    Route::get('/external/{external_id}', ServerExternalController::class)->name('.external');
    Route::get('/{server:admin_identifier}', [ServerController::class, 'show'])->name('.view');

    Route::post('/', [ServerController::class, 'store'])->name('.store');

    Route::put('/{server:admin_identifier}/details', DetailsController::class)->name('.details');
    Route::put('/{server:admin_identifier}/build', BuildController::class)->name('.build');
    Route::put('/{server:admin_identifier}/startup', StartupController::class)->name('.startup');

    Route::post('/{server:admin_identifier}/suspension', SuspensionController::class)->name('.suspension');
    Route::post('/{server:admin_identifier}/reinstall', ReinstallController::class)->name('.reinstall');
    Route::post('/{server:admin_identifier}/rebuild', RebuildController::class)->name('.rebuild');
    Route::get('/{server:admin_identifier}/transfer/progress', TransferProgressController::class)->name('.transfer-progress');
    Route::post('/{server:admin_identifier}/transfer', TransferController::class)->name('.transfer');
    Route::post('/{server:admin_identifier}/settings/toggle-install', ToggleInstallController::class)->name('.toggle-install');

    // Shop billing (phase 3 Task T).
    Route::get('/{server:admin_identifier}/subscription', [ServerSubscriptionController::class, 'show'])->name('.subscription');
    Route::post('/{server:admin_identifier}/subscription/extend', [ServerSubscriptionController::class, 'extend'])->name('.subscription.extend');
    Route::post('/{server:admin_identifier}/subscription/cancel', [ServerSubscriptionController::class, 'cancel'])->name('.subscription.cancel');

    Route::delete('/{server:admin_identifier}', [ServerController::class, 'destroy'])->name('.delete');
    Route::delete('/{server:admin_identifier}/force', [ServerController::class, 'forceDestroy'])->name('.delete.force');
});

/*
|--------------------------------------------------------------------------
| Server Database Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/servers/{server}/databases
|
*/
Route::prefix('/servers/{server:admin_identifier}/databases')->name('api.admin.servers.databases')->group(function (): void {
    Route::get('/', [DatabaseController::class, 'index']);
    Route::get('/{database:id}', [DatabaseController::class, 'show'])->name('.view');

    Route::post('/', [DatabaseController::class, 'store'])->name('.store');
    Route::post('/{database:id}/rotate-password', RotateDatabasePasswordController::class)->name('.rotate-password');

    Route::delete('/{database:id}', [DatabaseController::class, 'destroy'])->name('.delete');
});

/*
|--------------------------------------------------------------------------
| Server Backup Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/servers/{server}/backups
|
*/
Route::prefix('/servers/{server:admin_identifier}/backups')->name('api.admin.servers.backups')->group(function (): void {
    Route::get('/', [ServerBackupController::class, 'index']);
    Route::post('/{backup:uuid}/toggle', ToggleBackupController::class)->name('.toggle')->withoutScopedBindings();
});

/*
|--------------------------------------------------------------------------
| Node Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/nodes
|
*/
Route::prefix('/nodes')->name('api.admin.nodes')->group(function (): void {
    Route::get('/', [NodeController::class, 'index']);
    Route::get('/{node:id}', [NodeController::class, 'show'])->name('.view');
    Route::get('/{node:id}/configuration', ConfigurationController::class)->middleware(RequireSessionAuthentication::class)->name('.configuration');

    Route::post('/', [NodeController::class, 'store'])->name('.store');
    Route::put('/{node:id}', [NodeController::class, 'update'])->name('.update');

    Route::delete('/{node:id}', [NodeController::class, 'destroy'])->name('.delete');

    // A node carries two independent tag sets - the games it accepts and the
    // deploys it is reserved for - so each is replaced through its own endpoint.
    Route::put('/{node:id}/tags', [TagController::class, 'syncNode'])->name('.tags.sync');
    Route::put('/{node:id}/deployment-tags', [TagController::class, 'syncNodeDeploymentTags'])->name('.deployment-tags.sync');
});

/*
|--------------------------------------------------------------------------
| Allocation Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/nodes/{node}/allocations
|
*/
Route::prefix('/nodes/{node:id}/allocations')->name('api.admin.nodes.allocations')->group(function (): void {
    Route::get('/', [AllocationController::class, 'index']);
    // Literal segments (`/ips`, `/available`, `/block`) are declared before `/{allocation:id}`.
    Route::get('/ips', UniqueIpsController::class)->name('.ips');
    Route::get('/available', AvailableAllocationsController::class)->name('.available');
    Route::post('/', [AllocationController::class, 'store'])->name('.store');

    Route::delete('/block', DeleteAllocationBlockController::class)->name('.block');
    Route::delete('/', BulkDeleteAllocationsController::class)->name('.bulk-delete');

    Route::put('/{allocation:id}', [AllocationController::class, 'update'])->name('.update');
    Route::delete('/{allocation:id}', [AllocationController::class, 'destroy'])->name('.delete');
});

/*
|--------------------------------------------------------------------------
| Location Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/locations
|
*/
Route::prefix('/locations')->name('api.admin.locations')->group(function (): void {
    Route::get('/', [LocationController::class, 'index']);
    Route::get('/{location:id}', [LocationController::class, 'show'])->name('.view');
    Route::get('/{location:id}/eligible-nodes', EligibleNodesController::class)->name('.eligible-nodes');

    Route::post('/', [LocationController::class, 'store'])->name('.store');
    Route::put('/{location:id}', [LocationController::class, 'update'])->name('.update');

    Route::delete('/{location:id}', [LocationController::class, 'destroy'])->name('.delete');
});

/*
|--------------------------------------------------------------------------
| Mount Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/mounts
|
*/
Route::prefix('/mounts')->name('api.admin.mounts')->group(function (): void {
    Route::get('/', [MountController::class, 'index']);
    Route::get('/{mount:id}', [MountController::class, 'show'])->name('.view');

    Route::post('/', [MountController::class, 'store'])->name('.store');
    Route::put('/{mount:id}', [MountController::class, 'update'])->name('.update');

    Route::delete('/{mount:id}', [MountController::class, 'destroy'])->name('.delete');

    // Detach relies on global scoped binding resolving {egg}/{node} through the mount; keep it on.
    Route::post('/{mount:id}/eggs', AttachEggsController::class)->name('.eggs');
    Route::delete('/{mount:id}/eggs/{egg:id}', DetachEggController::class)->name('.eggs.delete');

    Route::post('/{mount:id}/nodes', AttachNodesController::class)->name('.nodes');
    Route::delete('/{mount:id}/nodes/{node:id}', DetachNodeController::class)->name('.nodes.delete');
});

// Catalog routes must precede /eggs/{egg}/import to avoid binding "catalog" as an egg ID.
Route::prefix('/eggs/catalog')->name('api.admin.eggs.catalog')->group(function (): void {
    Route::get('/', [EggCatalogController::class, 'index']);
    Route::post('/refresh', [EggCatalogController::class, 'refresh'])->name('.refresh');
    Route::post('/import', ImportCatalogEggController::class)->name('.import');
});

/*
|--------------------------------------------------------------------------
| Egg Variable Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/eggs/{egg}/variables
|
*/
Route::prefix('/eggs/{egg:id}/variables')->name('api.admin.eggs.variables')->group(function (): void {
    Route::get('/', [EggVariableController::class, 'index']);
    Route::post('/', [EggVariableController::class, 'store'])->name('.store');
    // `/reorder` before `/{variable:id}` so the literal is not bound as an id.
    Route::put('/reorder', ReorderVariablesController::class)->name('.reorder');
    Route::put('/{variable:id}', [EggVariableController::class, 'update'])->name('.update');
    Route::delete('/{variable:id}', [EggVariableController::class, 'destroy'])->name('.delete');
});

/*
|--------------------------------------------------------------------------
| Egg Script Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/eggs/{egg}/scripts
|
*/
Route::prefix('/eggs/{egg:id}/scripts')->name('api.admin.eggs.script')->group(function (): void {
    Route::get('/', [EggScriptController::class, 'show']);
    Route::put('/', [EggScriptController::class, 'update'])->name('.update');
});

/*
|--------------------------------------------------------------------------
| Egg Sharing and Tag Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/eggs/{egg}
|
*/
Route::prefix('/eggs/{egg:id}')->name('api.admin.eggs')->group(function (): void {
    Route::get('/export', ExportEggController::class)->name('.export');
    Route::post('/import', UpdateImportEggController::class)->name('.update-import');
    Route::put('/tags', [TagController::class, 'syncEgg'])->name('.tags.sync');
});

/*
|--------------------------------------------------------------------------
| Egg Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/eggs
|
| Eggs are addressed directly rather than through a nest: they are grouped by
| tags now, so there is no parent resource to route them under.
|
*/
Route::prefix('/eggs')->name('api.admin.eggs')->group(function (): void {
    // `/import` before `/{egg:id}` so the literal is not bound as an id.
    Route::post('/import', ImportEggController::class)->name('.import');

    Route::get('/', [EggController::class, 'index']);
    Route::get('/{egg:id}', [EggController::class, 'show'])->name('.view');

    Route::post('/', [EggController::class, 'store'])->name('.store');
    Route::put('/{egg:id}', [EggController::class, 'update'])->name('.update');

    Route::delete('/{egg:id}', [EggController::class, 'destroy'])->name('.delete');
});

/*
|--------------------------------------------------------------------------
| Tag Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/tags
|
| Tags are generic labels attached to eggs and nodes. They take over the
| grouping role nests used to play, and sit alongside a node's location.
|
*/
Route::prefix('/tags')->name('api.admin.tags')->group(function (): void {
    Route::get('/', [TagController::class, 'index']);
    Route::get('/{tag:id}', [TagController::class, 'show'])->name('.view');

    Route::post('/', [TagController::class, 'store'])->name('.store');
    Route::put('/{tag:id}', [TagController::class, 'update'])->name('.update');

    Route::delete('/{tag:id}', [TagController::class, 'destroy'])->name('.delete');
});

/*
|--------------------------------------------------------------------------
| Settings Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/settings
|
*/
Route::prefix('/settings')->name('api.admin.settings')->group(function (): void {
    Route::get('/', [SettingsController::class, 'index']);
    Route::put('/general', GeneralController::class)->name('.general');
    Route::put('/mail', MailController::class)->name('.mail');
    Route::post('/mail/test', TestMailController::class)->name('.mail.test');
    Route::put('/advanced', AdvancedController::class)->name('.advanced');
    // Phase 2 Task G: Discord and Google login providers.
    Route::get('/login-providers', [LoginProvidersController::class, 'index'])->name('.login-providers');
    Route::put('/login-providers', [LoginProvidersController::class, 'update'])->name('.login-providers.update');

    // Sign-up and captcha.
    Route::get('/registration', [RegistrationController::class, 'index'])->name('.registration');
    Route::put('/registration', [RegistrationController::class, 'update'])->name('.registration.update');

    // Terms of Service, Privacy Policy and Refund Policy.
    Route::get('/legal', [LegalController::class, 'index'])->name('.legal');
    Route::put('/legal/{document}', [LegalController::class, 'update'])
        ->whereIn('document', LegalDocument::values())
        ->name('.legal.update');

    // Phase 6: one-click plugins (the CurseForge API key).
    Route::get('/plugins', [PluginSettingsController::class, 'index'])->name('.plugins');
    Route::put('/plugins', [PluginSettingsController::class, 'update'])->name('.plugins.update');

    // The public status page and the nodes it shows.
    Route::get('/status', [StatusPageController::class, 'index'])->name('.status');
    Route::put('/status', [StatusPageController::class, 'update'])->name('.status.update');
});

/*
|--------------------------------------------------------------------------
| Database Host Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/database-hosts
|
*/
Route::prefix('/database-hosts')->name('api.admin.database-hosts')->group(function (): void {
    Route::get('/', [DatabaseHostController::class, 'index']);
    Route::get('/{databaseHost:id}', [DatabaseHostController::class, 'show'])->name('.view');
    Route::get('/{databaseHost:id}/databases', [DatabaseHostController::class, 'databases'])->name('.databases');

    Route::post('/', [DatabaseHostController::class, 'store'])->name('.store');
    Route::put('/{databaseHost:id}', [DatabaseHostController::class, 'update'])->name('.update');

    Route::delete('/{databaseHost:id}', [DatabaseHostController::class, 'destroy'])->name('.delete');
});

/*
|--------------------------------------------------------------------------
| API Key Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/api-keys
|
*/
Route::prefix('/api-keys')->name('api.admin.api-keys')->group(function (): void {
    Route::get('/', [ApiKeyController::class, 'index']);
    Route::get('/{identifier}', [ApiKeyController::class, 'show'])->name('.view');

    Route::post('/', [ApiKeyController::class, 'store'])->name('.store');
    Route::put('/{identifier}', [ApiKeyController::class, 'update'])->name('.update');

    Route::delete('/{identifier}', [ApiKeyController::class, 'destroy'])->name('.delete');
});

/*
|--------------------------------------------------------------------------
| Version Controller Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/version
|
*/
Route::prefix('/version')->name('api.admin.version')->group(function (): void {
    Route::get('/', VersionController::class);
});

/*
|--------------------------------------------------------------------------
| MonoForge Update Routes
|--------------------------------------------------------------------------
|
| Endpoint: /api/admin/updates
|
*/
Route::prefix('/updates')->name('api.admin.updates')->group(function (): void {
    Route::get('/status', [UpdateController::class, 'status'])->name('.status');

    Route::middleware(RequireSessionAuthentication::class)->group(function (): void {
        Route::post('/check', [UpdateController::class, 'check'])->middleware('throttle:monoforge.update-check')->name('.check');
        Route::post('/apply', [UpdateController::class, 'apply'])->name('.apply');
        Route::put('/settings', [UpdateController::class, 'settings'])->name('.settings');
    });
});
