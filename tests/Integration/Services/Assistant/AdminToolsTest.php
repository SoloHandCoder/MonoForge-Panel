<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Assistant\AdminToolsTest;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use LogicException;
use Pterodactyl\Contracts\Assistant\AssistantTool;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Announcement;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Providers\AssistantToolServiceProvider;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolRegistry;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Assistant\Tools\AbstractTool;
use Pterodactyl\Services\Assistant\Tools\Admin as Tools;
use Pterodactyl\Services\Billing\TokenWallet;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonServer;

uses(IntegrationTestCase::class);
uses(DatabaseTransactions::class);

function admin(): User
{
    return User::factory()->create(['root_admin' => true]);
}

function adminContext(User $user): ToolContext
{
    return new ToolContext($user, 'admin');
}

/**
 * @param  class-string<AssistantTool>  $class
 */
function adminTool(string $class): AssistantTool
{
    return resolve($class);
}

function registered(string $name): AssistantTool
{
    foreach (resolve(ToolRegistry::class)->for('admin', adminContext(admin())) as $tool) {
        if ($tool->name() === $name) {
            return $tool;
        }
    }

    throw new LogicException("No admin tool {$name}.");
}

test('root admins are offered every admin tool and nobody else is', function (): void {
    [$player, $server] = $this->generateTestAccount();
    $names = array_map(fn (AssistantTool $tool): string => $tool->name(), resolve(ToolRegistry::class)->for('admin', adminContext(admin())));

    expect($names)->toHaveCount(count(AssistantToolServiceProvider::ADMIN_TOOLS) + 2)
        ->toContain('list_servers', 'server_overview', 'list_users', 'node_usage', 'search_activity', 'shop_overview', 'suspend_server', 'unsuspend_server', 'create_announcement', 'adjust_tokens')
        ->and(resolve(ToolRegistry::class)->for('admin', adminContext($player)))->toBe([])
        // An admin tool is never offered in the server assistant, even to a root admin.
        ->and(resolve(ToolRegistry::class)->for('server', new ToolContext(admin(), 'server', $server)))->each(fn ($tool) => $tool->not->toBeInstanceOf(Tools\AdminTool::class));
});

test('a non-admin cannot run an admin tool', function (string $name, array $arguments): void {
    [$player, $server] = $this->generateTestAccount();
    $fake = new FakeDaemonServer;

    expect(fn (): ToolResult => registered($name)->execute(str_replace('{server}', $server->uuid, $arguments), adminContext($player)))
        ->toThrow(ToolException::class, AbstractTool::DENIED);
    expect($server->refresh()->isSuspended())->toBeFalse()
        ->and(Announcement::query()->count())->toBe(0);
    $fake->assertNothingHappened();
})->with([
    'list_servers' => ['list_servers', []],
    'server_overview' => ['server_overview', ['server' => '{server}']],
    'list_users' => ['list_users', []],
    'node_usage' => ['node_usage', []],
    'search_activity' => ['search_activity', []],
    'shop_overview' => ['shop_overview', []],
    'suspend_server' => ['suspend_server', ['server' => '{server}']],
    'create_announcement' => ['create_announcement', ['title' => 'Hi', 'body' => 'Hello']],
    'adjust_tokens' => ['adjust_tokens', ['user' => '1', 'amount' => 10, 'reason' => 'gift']],
]);

test('an admin whose admin flag was removed is refused when the tool runs', function (): void {
    $user = admin();
    $context = adminContext($user);
    $tool = registered('list_users');
    User::query()->whereKey($user->id)->update(['root_admin' => false]);

    expect(fn (): ToolResult => $tool->execute([], $context))->toThrow(ToolException::class, AbstractTool::DENIED);
});

test('changing admin tools need confirmation and reading ones do not', function (): void {
    $context = adminContext(admin());
    foreach (['suspend_server', 'unsuspend_server', 'create_announcement', 'adjust_tokens'] as $name) {
        expect(registered($name)->needsConfirmation([], $context))->toBeTrue();
    }

    foreach (['list_servers', 'server_overview', 'list_users', 'node_usage', 'search_activity', 'shop_overview'] as $name) {
        expect(registered($name)->needsConfirmation([], $context))->toBeFalse();
    }
});

test('servers are searched without node addresses', function (): void {
    $server = $this->createServerModel(['name' => 'Survival World']);
    Node::query()->whereKey($server->node_id)->update(['fqdn' => 'de-wings-1.example.net']);
    $this->createServerModel(['name' => 'Creative']);

    $result = adminTool(Tools\ListServersTool::class)->execute(['search' => 'surv'], adminContext(admin()));
    $data = json_decode($result->content, true);

    expect($data['total'])->toBe(1)
        ->and($data['servers'][0])->toMatchArray(['id' => $server->id, 'name' => 'Survival World'])
        ->and($result->content)->not->toContain('de-wings-1')->not->toContain($server->allocation->ip);
});

test('a server overview never holds an address', function (): void {
    $server = $this->createServerModel();
    Node::query()->whereKey($server->node_id)->update(['fqdn' => 'de-wings-2.example.net']);
    $server->allocation->update(['ip' => '203.0.113.9', 'ip_alias' => 'play.node-alias.example']);
    $fake = new FakeDaemonServer;
    $fake->details = ['state' => 'running'];

    $result = adminTool(Tools\ServerOverviewTool::class)->execute(['server' => $server->uuidShort], adminContext(admin()));

    expect(json_decode($result->content, true))->toMatchArray(['id' => $server->id, 'live' => ['state' => 'running', 'memory_mib' => 0, 'cpu_percent' => 0, 'disk_mib' => 0]])
        ->and($result->content)->not->toContain('203.0.113.9')->not->toContain('node-alias')->not->toContain('de-wings-2');
    expect(fn (): ToolResult => adminTool(Tools\ServerOverviewTool::class)->execute(['server' => 'nope'], adminContext(admin())))->toThrow(ToolException::class, 'No server matches');
});

test('users are listed without passwords or secrets', function (): void {
    $user = User::factory()->create(['username' => 'steve_builder', 'email' => 'steve@example.com']);

    $result = adminTool(Tools\ListUsersTool::class)->execute(['search' => 'steve'], adminContext(admin()));
    $data = json_decode($result->content, true);

    expect($data['users'])->toHaveCount(1)
        ->and($data['users'][0])->toMatchArray(['id' => $user->id, 'username' => 'steve_builder', 'email' => 'steve@example.com'])
        ->and($data['users'][0])->not->toHaveKeys(['password', 'totp_secret', 'remember_token'])
        ->and($result->content)->not->toContain((string) $user->password);
});

test('node usage names nodes without their address', function (): void {
    $server = $this->createServerModel(['name' => 'Busy']);
    Node::query()->whereKey($server->node_id)->update(['fqdn' => 'usage-node.example.test', 'name' => 'Alpha']);
    Http::fake(fn (HttpRequest $request): PromiseInterface => match (parse_url($request->url(), PHP_URL_PATH)) {
        '/api/system' => Http::response(['cpu_count' => 4]),
        "/api/servers/{$server->uuid}" => Http::response(['state' => 'running', 'utilization' => ['cpu_absolute' => 50, 'memory_bytes' => 512 * 1_048_576, 'disk_bytes' => 0]]),
        default => Http::response([], 404),
    });

    $result = adminTool(Tools\NodeUsageTool::class)->execute([], adminContext(admin()));
    $node = collect(json_decode($result->content, true)['nodes'])->firstWhere('name', 'Alpha');

    expect($node)->toMatchArray(['status' => 'online', 'servers_online' => 1])
        ->and($node['busiest_servers'][0])->toMatchArray(['name' => 'Busy', 'memory_mib' => 512])
        ->and($result->content)->not->toContain('usage-node');
});

test('activity is searched without addresses', function (): void {
    $player = User::factory()->create(['username' => 'griefer']);
    ActivityLog::factory()->create(['event' => 'auth:fail', 'ip' => '198.51.100.77', 'actor_type' => $player->getMorphClass(), 'actor_id' => $player->id, 'properties' => ['ip' => '198.51.100.77', 'fqdn' => 'n.example', 'username' => 'griefer', 'note' => 'came from 198.51.100.78']]);
    ActivityLog::factory()->create(['event' => 'server:power.start']);

    $result = adminTool(Tools\SearchActivityTool::class)->execute(['event' => 'auth:', 'user' => 'grief'], adminContext(admin()));
    $entries = json_decode($result->content, true)['entries'];

    expect($entries)->toHaveCount(1)
        ->and($entries[0])->toMatchArray(['event' => 'auth:fail', 'actor' => 'griefer'])
        ->and($entries[0]['properties'])->not->toHaveKeys(['ip', 'fqdn'])
        ->and($result->content)->not->toContain('198.51.100');
});

test('servers are suspended and unsuspended', function (): void {
    $server = $this->createServerModel();
    $fake = new FakeDaemonServer;
    $admin = admin();

    registered('suspend_server')->execute(['server' => (string) $server->id], adminContext($admin));
    expect($server->refresh()->isSuspended())->toBeTrue();
    $fake->assertSynced();
    $this->assertActivityActor('admin:server.suspend', $admin);

    expect(fn (): ToolResult => registered('suspend_server')->execute(['server' => $server->uuid], adminContext($admin)))->toThrow(ToolException::class, 'already suspended');

    registered('unsuspend_server')->execute(['server' => $server->uuid], adminContext($admin));
    expect($server->refresh()->isSuspended())->toBeFalse();
});

test('announcements are validated and published', function (): void {
    $tool = adminTool(Tools\CreateAnnouncementTool::class);

    expect(fn (): ToolResult => $tool->execute(['title' => 'Maintenance', 'body' => 'Tonight', 'level' => 'shouting'], adminContext(admin())))->toThrow(ToolException::class)
        ->and(fn (): ToolResult => $tool->execute(['title' => 'Maintenance', 'body' => 'x', 'starts_at' => '2026-12-02', 'ends_at' => '2026-12-01'], adminContext(admin())))->toThrow(ToolException::class);

    $tool->execute(['title' => 'Maintenance', 'body' => 'Tonight at 22:00', 'level' => 'warning', 'dismissible' => false], adminContext(admin()));

    $announcement = Announcement::query()->sole();
    expect($announcement->title)->toBe('Maintenance')
        ->and($announcement->level->value)->toBe('warning')
        ->and($announcement->dismissible)->toBeFalse();
    $this->assertActivityLogged('admin:announcement.create');
});

test('tokens are adjusted within the token endpoint limits', function (): void {
    $admin = admin();
    $player = User::factory()->create();
    $tool = adminTool(Tools\AdjustTokensTool::class);

    expect(fn (): ToolResult => $tool->execute(['user' => (string) $admin->id, 'amount' => 10, 'reason' => 'self gift'], adminContext($admin)))->toThrow(ToolException::class, 'own token balance')
        ->and(fn (): ToolResult => $tool->execute(['user' => $player->email, 'amount' => 1_000_001, 'reason' => 'too much'], adminContext($admin)))->toThrow(ToolException::class, 'between')
        ->and(fn (): ToolResult => $tool->execute(['user' => $player->email, 'amount' => 0, 'reason' => 'nothing'], adminContext($admin)))->toThrow(ToolException::class, 'between')
        ->and(fn (): ToolResult => $tool->execute(['user' => $player->email, 'amount' => 5, 'reason' => 'x'], adminContext($admin)))->toThrow(ToolException::class, 'at least 3')
        ->and(fn (): ToolResult => $tool->execute(['user' => $player->email, 'amount' => -5, 'reason' => 'refund'], adminContext($admin)))->toThrow(ToolException::class, 'only has 0 tokens');

    $result = $tool->execute(['user' => $player->username, 'amount' => 250, 'reason' => 'Event prize'], adminContext($admin));

    expect(resolve(TokenWallet::class)->balance($player))->toBe(250)
        ->and($result->display)->toBe(['user_id' => $player->id, 'amount' => 250, 'balance' => 250]);
    $this->assertActivityActor('admin:user.adjust-tokens', $admin);
});

test('the shop overview is summarised', function (): void {
    User::factory()->create(['token_balance' => 40]);

    $data = json_decode(adminTool(Tools\ShopOverviewTool::class)->execute([], adminContext(admin()))->content, true);

    expect($data)->toHaveKeys(['shop_enabled', 'plans', 'subscriptions', 'tokens_held_by_users', 'last_30_days'])
        ->and($data['tokens_held_by_users'])->toBeGreaterThanOrEqual(40);
});

test('the server scope cannot run admin tools', function (): void {
    [, $server] = $this->generateTestAccount();

    expect(fn (): ToolResult => adminTool(Tools\ListUsersTool::class)->execute([], new ToolContext(admin(), 'server', $server)))
        ->toThrow(ToolException::class, AbstractTool::DENIED);
    expect(Server::query()->count())->toBeGreaterThan(0);
});
