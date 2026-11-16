<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Pterodactyl\Enum\ResourceLimit;
use Pterodactyl\Http\Controllers\Api\Client;
use Pterodactyl\Http\Controllers\Api\Client\AccountController;
use Pterodactyl\Http\Controllers\Api\Client\ActivityLogController;
use Pterodactyl\Http\Controllers\Api\Client\ActivityLogFilterController;
use Pterodactyl\Http\Controllers\Api\Client\ApiKeyController;
use Pterodactyl\Http\Controllers\Api\Client\Assistant\AssistantController;
use Pterodactyl\Http\Controllers\Api\Client\Assistant\ConversationController as AssistantConversationController;
use Pterodactyl\Http\Controllers\Api\Client\ClientController;
use Pterodactyl\Http\Controllers\Api\Client\ExtensionProgressController;
use Pterodactyl\Http\Controllers\Api\Client\FriendController;
use Pterodactyl\Http\Controllers\Api\Client\LegalAcceptanceController;
use Pterodactyl\Http\Controllers\Api\Client\NoticeController;
use Pterodactyl\Http\Controllers\Api\Client\OAuthAccountController;
use Pterodactyl\Http\Controllers\Api\Client\PermissionTemplateController;
use Pterodactyl\Http\Controllers\Api\Client\ServerResourcesController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\BackupController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\CommandController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\CrashDoctorController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\DatabaseController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\FileController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\FileUploadController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\LogController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\NetworkAllocationController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\Plugins\ModpackController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\Plugins\PluginController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\PowerController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\ResourceUtilizationController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\ScheduleController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\SchedulePresetController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\ScheduleTaskController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\ServerController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\ServerPageController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\SettingsController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\SleepController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\StartupController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\SubuserController;
use Pterodactyl\Http\Controllers\Api\Client\Servers\WebsocketController;
use Pterodactyl\Http\Controllers\Api\Client\SSHKeyController;
use Pterodactyl\Http\Controllers\Api\Client\Store\TokenPackController;
use Pterodactyl\Http\Controllers\Api\Client\StoreController;
use Pterodactyl\Http\Controllers\Api\Client\StorePurchaseController;
use Pterodactyl\Http\Controllers\Api\Client\StoreTrialController;
use Pterodactyl\Http\Controllers\Api\Client\SubscriptionController;
use Pterodactyl\Http\Controllers\Api\Client\Support\SupportTicketController;
use Pterodactyl\Http\Controllers\Api\Client\TokenPurchaseController;
use Pterodactyl\Http\Controllers\Api\Client\TwoFactorController;
use Pterodactyl\Http\Controllers\Api\Client\WalletController;
use Pterodactyl\Http\Middleware\Activity\AccountSubject;
use Pterodactyl\Http\Middleware\Activity\ServerSubject;
use Pterodactyl\Http\Middleware\Api\Admin\RequireSessionAuthentication;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use Pterodactyl\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;

/*
|--------------------------------------------------------------------------
| Client Control API
|--------------------------------------------------------------------------
|
| Endpoint: /api/client
|
*/
Route::get('/', [ClientController::class, 'index'])->name('api:client.index');
Route::get('/permissions', [ClientController::class, 'permissions']);
Route::get('/extension-progress/{extension}/{job}', [ExtensionProgressController::class, 'user'])->where('extension', '[a-z][a-z0-9-]{0,47}')->whereUuid('job')->name('api:client.extension-progress');

// The dashboard's server cards read their resources in one request.
Route::get('/resources', ServerResourcesController::class)->name('api:client.resources');

// The AI assistant: whether it is on, and the user's free AI tokens and balance (phase 4).
Route::get('/assistant', AssistantController::class)->name('api:client.assistant');

Route::get('/notices', [NoticeController::class, 'index'])->name('api:client.notices');
Route::post('/notices/{notice}/read', [NoticeController::class, 'read'])->whereNumber('notice')->name('api:client.notices.read');

// Support tickets (phase 6 Task K). Tickets are looked up among the user's own, so another
// user's ticket is not found.
Route::prefix('/support')->name('api:client.support')->group(function (): void {
    Route::get('/options', [SupportTicketController::class, 'options'])->name('.options');
    Route::get('/tickets', [SupportTicketController::class, 'index'])->name('.tickets');
    Route::post('/tickets', [SupportTicketController::class, 'store'])->middleware('throttle:support.ticket')->name('.tickets.store');
    Route::get('/tickets/{ticket}', [SupportTicketController::class, 'show'])->whereNumber('ticket')->name('.tickets.view');
    Route::post('/tickets/{ticket}/messages', [SupportTicketController::class, 'reply'])->whereNumber('ticket')->middleware('throttle:support.reply')->name('.tickets.reply');
    Route::post('/tickets/{ticket}/close', [SupportTicketController::class, 'close'])->whereNumber('ticket')->name('.tickets.close');
    Route::get('/tickets/{ticket}/attachments/{attachment}', [SupportTicketController::class, 'attachment'])->whereNumber(['ticket', 'attachment'])->name('.tickets.attachment');
});

// The store: catalog, quotes and buying a server.
Route::get('/store/catalog', [StoreController::class, 'catalog'])->name('api:client.store.catalog');
Route::post('/store/quote', [StoreController::class, 'quote'])->name('api:client.store.quote');
Route::post('/store/purchase', [StorePurchaseController::class, 'store'])->middleware('throttle:store.purchase')->name('api:client.store.purchase');
// The one free trial server of a player: browser sessions only, behind the captcha.
Route::post('/store/trial', [StoreTrialController::class, 'store'])
    ->middleware([RequireSessionAuthentication::class, 'captcha:trial', 'throttle:store.purchase'])
    ->name('api:client.store.trial');
// Buying tokens through Tebex.
Route::get('/store/tokens/packs', [TokenPackController::class, 'index'])->name('api:client.store.tokens.packs');
Route::post('/store/tokens/checkout', [TokenPackController::class, 'checkout'])
    ->middleware('throttle:10,1')
    ->name('api:client.store.tokens.checkout');
// Shop billing of the user's servers. Outside the server group: a suspended server's renewal must still work.
Route::get('/subscriptions', [SubscriptionController::class, 'index'])->name('api:client.subscriptions');
Route::patch('/subscriptions/{server}', [SubscriptionController::class, 'update'])->name('api:client.subscriptions.update');
Route::post('/subscriptions/{server}/renew', [SubscriptionController::class, 'renew'])->name('api:client.subscriptions.renew');
Route::post('/subscriptions/{server}/keep', [SubscriptionController::class, 'keep'])->middleware('throttle:10,1')->name('api:client.subscriptions.keep');

Route::prefix('/account')->middleware(AccountSubject::class)->group(function (): void {
    Route::prefix('/')->withoutMiddleware(RequireTwoFactorAuthentication::class)->group(function (): void {
        Route::get('/', [AccountController::class, 'index'])->name('api:client.account');
        Route::get('/two-factor', [TwoFactorController::class, 'index']);
        Route::post('/two-factor', [TwoFactorController::class, 'store']);
        Route::post('/two-factor/disable', [TwoFactorController::class, 'delete']);
        // Outside the two-factor requirement: the panel asks for the updated terms before
        // a user who still has to set up two-factor authentication can reach that page.
        Route::post('/legal/accept', LegalAcceptanceController::class)->name('api:client.account.legal.accept');
    });

    Route::put('/email', [AccountController::class, 'updateEmail'])
        ->middleware('throttle')
        ->name('api:client.account.update-email');
    Route::put('/password', [AccountController::class, 'updatePassword'])->name('api:client.account.update-password');

    Route::get('/activity', ActivityLogController::class)->name('api:client.account.activity');
    Route::get('/activity/filters', ActivityLogFilterController::class)->name('api:client.account.activity.filters');

    // The token balance and history (phase 3 Task Q).
    Route::get('/wallet', WalletController::class)->name('api:client.account.wallet');
    // The return page after a Tebex token checkout follows the user's own checkout (phase 5 Task T).
    Route::get('/wallet/purchases/{checkout}', TokenPurchaseController::class)->name('api:client.account.wallet.purchase');

    Route::get('/api-keys', [ApiKeyController::class, 'index']);
    Route::post('/api-keys', [ApiKeyController::class, 'store']);
    Route::delete('/api-keys/{identifier}', [ApiKeyController::class, 'delete']);

    // Friends, whom a subuser invite can pick (phase 6 Task F). Requests never show whether a name exists.
    Route::prefix('/friends')->group(function (): void {
        Route::get('/', [FriendController::class, 'index'])->name('api:client.account.friends');
        Route::post('/requests', [FriendController::class, 'store'])
            ->middleware('throttle:friend.request')
            ->name('api:client.account.friends.request');
        Route::post('/requests/{friendRequest}/accept', [FriendController::class, 'accept'])->whereNumber('friendRequest')->name('api:client.account.friends.accept');
        Route::post('/requests/{friendRequest}/decline', [FriendController::class, 'decline'])->whereNumber('friendRequest')->name('api:client.account.friends.decline');
        Route::delete('/requests/{friendRequest}', [FriendController::class, 'cancel'])->whereNumber('friendRequest')->name('api:client.account.friends.cancel');
        Route::delete('/{friend}', [FriendController::class, 'delete'])->whereUuid('friend')->name('api:client.account.friends.delete');
    });

    // Subuser permission templates: built-in, global and the user's own.
    Route::prefix('/permission-templates')->group(function (): void {
        Route::get('/', [PermissionTemplateController::class, 'index'])->name('api:client.account.permission-templates');
        Route::post('/', [PermissionTemplateController::class, 'store'])->name('api:client.account.permission-templates.store');
        Route::put('/{template}', [PermissionTemplateController::class, 'update'])->whereNumber('template')->name('api:client.account.permission-templates.update');
        Route::delete('/{template}', [PermissionTemplateController::class, 'delete'])->whereNumber('template')->name('api:client.account.permission-templates.delete');
    });

    Route::prefix('/ssh-keys')->group(function (): void {
        Route::get('/', [SSHKeyController::class, 'index']);
        Route::post('/', [SSHKeyController::class, 'store']);
        Route::post('/remove', [SSHKeyController::class, 'delete']);
    });

    // Discord and Google accounts linked for signing in (phase 2 Task G).
    Route::prefix('/oauth')->group(function (): void {
        Route::get('/', [OAuthAccountController::class, 'index'])->name('api:client.account.oauth');
        Route::post('/password', [OAuthAccountController::class, 'setPassword'])->name('api:client.account.oauth.password');
        Route::post('/{provider}/link', [OAuthAccountController::class, 'link'])
            ->whereIn('provider', ['discord', 'google'])
            ->name('api:client.account.oauth.link');
        Route::delete('/{provider}', [OAuthAccountController::class, 'unlink'])
            ->whereIn('provider', ['discord', 'google'])
            ->name('api:client.account.oauth.unlink');
    });
});

/*
|--------------------------------------------------------------------------
| Client Control API
|--------------------------------------------------------------------------
|
| Endpoint: /api/client/servers/{server}
|
*/
Route::group([
    'prefix' => '/servers/{server}',
    'middleware' => [
        ServerSubject::class,
        AuthenticateServerAccess::class,
        ResourceBelongsToServer::class,
    ],
], function (): void {
    Route::get('/', [ServerController::class, 'index'])->name('api:client:server.view');
    Route::middleware([ResourceLimit::Websocket->middleware()])
        ->get('/websocket', WebsocketController::class)
        ->name('api:client:server.ws');
    Route::get('/resources', ResourceUtilizationController::class)->name('api:client:server.resources');
    Route::get('/logs', LogController::class)->name('api:client:server.logs');
    Route::get('/extension-progress/{extension}/{job}', [ExtensionProgressController::class, 'server'])->where('extension', '[a-z][a-z0-9-]{0,47}')->whereUuid('job')->name('api:client:server.extension-progress');
    Route::get('/activity', Client\Servers\ActivityLogController::class)->name('api:client:server.activity');
    Route::get('/activity/filters', Client\Servers\ActivityLogFilterController::class)->name('api:client:server.activity.filters');

    Route::post('/command', [CommandController::class, 'index']);
    Route::post('/power', [PowerController::class, 'index']);
    // Pay-as-you-play: whether auto-sleep stopped the server, and waking it.
    Route::get('/sleep', [SleepController::class, 'show'])->name('api:client:server.sleep');
    Route::post('/sleep/wake', [SleepController::class, 'wake'])->middleware('throttle:6,1')->name('api:client:server.sleep.wake');

    Route::group(['prefix' => '/databases'], function (): void {
        Route::get('/', [DatabaseController::class, 'index']);
        Route::middleware([ResourceLimit::Database->middleware()])
            ->post('/', [DatabaseController::class, 'store']);
        Route::post('/{database}/rotate-password', [DatabaseController::class, 'rotatePassword']);
        Route::delete('/{database}', [DatabaseController::class, 'delete']);
    });

    Route::group(['prefix' => '/files'], function (): void {
        Route::get('/list', [FileController::class, 'directory']);
        Route::middleware('throttle:files.search')->get('/search', [FileController::class, 'search']);
        Route::get('/contents', [FileController::class, 'contents']);
        Route::get('/download', [FileController::class, 'download']);
        Route::put('/rename', [FileController::class, 'rename']);
        Route::post('/copy', [FileController::class, 'copy']);
        Route::post('/write', [FileController::class, 'write']);
        Route::post('/compress', [FileController::class, 'compress']);
        Route::post('/decompress', [FileController::class, 'decompress']);
        Route::post('/delete', [FileController::class, 'delete']);
        Route::post('/create-folder', [FileController::class, 'create']);
        Route::post('/chmod', [FileController::class, 'chmod']);
        Route::middleware([ResourceLimit::FilePull->middleware()])
            ->post('/pull', [FileController::class, 'pull']);
        Route::get('/upload', FileUploadController::class);
    });

    Route::group(['prefix' => '/schedules'], function (): void {
        Route::get('/', [ScheduleController::class, 'index']);
        Route::middleware([ResourceLimit::Schedule->middleware()])
            ->post('/', [ScheduleController::class, 'store']);
        // Quick setup presets: registered before /{schedule} so the word is not taken for an id.
        Route::get('/presets', [SchedulePresetController::class, 'index']);
        Route::middleware([ResourceLimit::Schedule->middleware()])
            ->post('/presets', [SchedulePresetController::class, 'store']);
        Route::get('/{schedule}', [ScheduleController::class, 'view']);
        Route::post('/{schedule}', [ScheduleController::class, 'update']);
        Route::post('/{schedule}/execute', [ScheduleController::class, 'execute']);
        Route::delete('/{schedule}', [ScheduleController::class, 'delete']);

        Route::post('/{schedule}/tasks', [ScheduleTaskController::class, 'store']);
        Route::post('/{schedule}/tasks/{task}', [ScheduleTaskController::class, 'update']);
        Route::delete('/{schedule}/tasks/{task}', [ScheduleTaskController::class, 'delete']);
    });

    Route::group(['prefix' => '/network'], function (): void {
        Route::get('/allocations', [NetworkAllocationController::class, 'index']);
        Route::middleware([ResourceLimit::Allocation->middleware()])
            ->post('/allocations', [NetworkAllocationController::class, 'store']);
        Route::post('/allocations/{allocation}', [NetworkAllocationController::class, 'update']);
        Route::post('/allocations/{allocation}/primary', [NetworkAllocationController::class, 'setPrimary']);
        Route::delete('/allocations/{allocation}', [NetworkAllocationController::class, 'delete']);
    });

    Route::group(['prefix' => '/users'], function (): void {
        Route::get('/', [SubuserController::class, 'index']);
        Route::middleware([ResourceLimit::Subuser->middleware()])
            ->post('/', [SubuserController::class, 'store']);
        Route::get('/{user}', [SubuserController::class, 'view']);
        Route::post('/{user}', [SubuserController::class, 'update']);
        Route::delete('/{user}', [SubuserController::class, 'delete']);
    });

    Route::group(['prefix' => '/backups'], function (): void {
        Route::get('/', [BackupController::class, 'index']);
        Route::post('/', [BackupController::class, 'store']);
        Route::get('/{backup}', [BackupController::class, 'view']);
        Route::get('/{backup}/download', [BackupController::class, 'download']);
        Route::post('/{backup}/lock', [BackupController::class, 'toggleLock']);
        Route::middleware([ResourceLimit::Backup->middleware()])
            ->post('/{backup}/restore', [BackupController::class, 'restore']);
        Route::delete('/{backup}', [BackupController::class, 'delete']);
    });

    Route::group(['prefix' => '/startup'], function (): void {
        Route::get('/', [StartupController::class, 'index']);
        Route::put('/variable', [StartupController::class, 'update']);
    });

    // The server AI assistant (phase 4). Conversations belong to the user who started them;
    // ResourceBelongsToServer checks that, so bindings are not scoped through the server.
    Route::prefix('/assistant/conversations')->group(function (): void {
        Route::get('/', [AssistantConversationController::class, 'index'])->name('api:client:server.assistant')->withoutScopedBindings();
        Route::post('/', [AssistantConversationController::class, 'store'])->middleware('throttle:20,1')->withoutScopedBindings();
        Route::get('/{conversation}', [AssistantConversationController::class, 'show'])->withoutScopedBindings();
        Route::post('/{conversation}/messages', [AssistantConversationController::class, 'message'])->middleware('throttle:30,1')->withoutScopedBindings();
        // A human click: from a panel session only, never an API key.
        Route::post('/{conversation}/tool-calls/{toolCall}/approve', [AssistantConversationController::class, 'approve'])->middleware(RequireSessionAuthentication::class)->withoutScopedBindings();
        Route::post('/{conversation}/tool-calls/{toolCall}/deny', [AssistantConversationController::class, 'deny'])->middleware(RequireSessionAuthentication::class)->withoutScopedBindings();
        Route::delete('/{conversation}', [AssistantConversationController::class, 'destroy'])->withoutScopedBindings();
    });

    // Phase 6: the shareable public page at /s/{slug}.
    Route::group(['prefix' => '/page'], function (): void {
        Route::get('/', [ServerPageController::class, 'show']);
        Route::put('/', [ServerPageController::class, 'update']);
        Route::middleware('throttle:10,1')->post('/banner', [ServerPageController::class, 'uploadBanner']);
        Route::delete('/banner', [ServerPageController::class, 'deleteBanner']);
    });

    // The AI crash doctor (phase 6): the crash notice, a crash the console saw, and the diagnosis.
    Route::prefix('/crash-doctor')->group(function (): void {
        Route::get('/', [CrashDoctorController::class, 'show']);
        Route::post('/report', [CrashDoctorController::class, 'report'])->middleware('throttle:6,1');
        Route::post('/diagnose', [CrashDoctorController::class, 'diagnose'])->middleware('throttle:20,1');
        Route::post('/crashes/{crash}/dismiss', [CrashDoctorController::class, 'dismiss'])->withoutScopedBindings();
    });

    // Phase 6: one-click plugins, mods and Modrinth modpacks.
    Route::prefix('/plugins')->group(function (): void {
        Route::get('/', [PluginController::class, 'environment'])->name('api:client:server.plugins');
        Route::middleware('throttle:plugins.browse')->group(function (): void {
            Route::get('/search', [PluginController::class, 'search']);
            Route::get('/versions', [PluginController::class, 'versions']);
            Route::get('/installed', [PluginController::class, 'installed']);
        });
        Route::middleware('throttle:plugins.manage')->group(function (): void {
            Route::post('/install', [PluginController::class, 'install']);
            Route::post('/installed/update', [PluginController::class, 'update']);
            Route::post('/installed/remove', [PluginController::class, 'remove']);
            Route::post('/modpack', [ModpackController::class, 'store']);
        });
        Route::get('/modpack', [ModpackController::class, 'progress']);
        Route::get('/install/{installJob}', [PluginController::class, 'installProgress']);
    });

    Route::group(['prefix' => '/settings'], function (): void {
        Route::post('/rename', [SettingsController::class, 'rename']);
        Route::post('/reinstall', [SettingsController::class, 'reinstall']);
        Route::put('/docker-image', [SettingsController::class, 'dockerImage']);
    });
});
