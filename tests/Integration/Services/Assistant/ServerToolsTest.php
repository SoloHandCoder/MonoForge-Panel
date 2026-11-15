<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Assistant\ServerToolsTest;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Pterodactyl\Contracts\Assistant\AssistantTool;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Enum\TunnelStatus;
use Pterodactyl\Exceptions\Assistant\ToolException;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Billing\ServerTunnel;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Schedule;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\User;
use Pterodactyl\Providers\AssistantToolServiceProvider;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolRegistry;
use Pterodactyl\Services\Assistant\ToolResult;
use Pterodactyl\Services\Assistant\Tools\AbstractTool;
use Pterodactyl\Services\Assistant\Tools\OutputRedactor;
use Pterodactyl\Services\Assistant\Tools\Server as Tools;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonBackup;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonCommand;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonFile;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonPower;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonServer;

uses(IntegrationTestCase::class);
uses(DatabaseTransactions::class);

function context(User $user, Server $server): ToolContext
{
    return new ToolContext($user, 'server', $server);
}

/**
 * @param  class-string<AssistantTool>  $class
 */
function tool(string $class): AssistantTool
{
    return resolve($class);
}

/**
 * @return array{file: FakeDaemonFile, server: FakeDaemonServer, power: FakeDaemonPower, command: FakeDaemonCommand, backup: FakeDaemonBackup}
 */
function wings(): array
{
    return [
        'file' => new FakeDaemonFile,
        'server' => new FakeDaemonServer,
        'power' => new FakeDaemonPower,
        'command' => new FakeDaemonCommand,
        'backup' => new FakeDaemonBackup,
    ];
}

/** @param  array{file: FakeDaemonFile, server: FakeDaemonServer, power: FakeDaemonPower, command: FakeDaemonCommand, backup: FakeDaemonBackup}  $fakes */
function assertNoWingsCalls(array $fakes): void
{
    foreach ($fakes as $fake) {
        $fake->assertNothingHappened();
    }
}

test('every server tool is registered under the server scope', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $names = array_map(fn (AssistantTool $tool): string => $tool->name(), resolve(ToolRegistry::class)->for('server', context($user, $server)));

    expect($names)->toHaveCount(count(AssistantToolServiceProvider::SERVER_TOOLS))
        ->toContain('server_status', 'read_console', 'list_files', 'read_file', 'search_files', 'list_backups', 'list_schedules', 'get_startup', 'get_network', 'write_file', 'delete_files', 'rename_file', 'send_command', 'power', 'create_backup', 'update_startup_variable');
});

test('the registry only offers a subuser the tools their permissions allow', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::FileRead->value, Permissions::ControlStart->value]);
    $names = array_map(fn (AssistantTool $tool): string => $tool->name(), resolve(ToolRegistry::class)->for('server', context($user, $server)));

    expect($names)->toEqualCanonicalizing(['server_status', 'list_files', 'search_files', 'power']);
});

test('no server tool is offered to someone without access to the server', function (): void {
    [$user] = $this->generateTestAccount();
    [, $server] = $this->generateTestAccount();

    expect(resolve(ToolRegistry::class)->for('server', context($user, $server)))->toBe([])
        ->and(resolve(ToolRegistry::class)->for('admin', context($user, $server)))->toBe([]);
});

test('a subuser without the permission cannot run the tool', function (string $class, array $arguments): void {
    [$user, $server] = $this->generateTestAccount([Permissions::DatabaseRead->value]);
    $fakes = wings();

    expect(fn (): ToolResult => tool($class)->execute($arguments, context($user, $server)))
        ->toThrow(ToolException::class, AbstractTool::DENIED);
    assertNoWingsCalls($fakes);
})->with([
    'read_console' => [Tools\ReadConsoleTool::class, []],
    'list_files' => [Tools\ListFilesTool::class, []],
    'read_file' => [Tools\ReadFileTool::class, ['path' => '/server.properties']],
    'search_files' => [Tools\SearchFilesTool::class, ['query' => 'x']],
    'list_backups' => [Tools\ListBackupsTool::class, []],
    'list_schedules' => [Tools\ListSchedulesTool::class, []],
    'get_startup' => [Tools\GetStartupTool::class, []],
    'get_network' => [Tools\GetNetworkTool::class, []],
    'write_file' => [Tools\WriteFileTool::class, ['path' => '/a.txt', 'content' => 'x']],
    'delete_files' => [Tools\DeleteFilesTool::class, ['files' => ['a.txt']]],
    'rename_file' => [Tools\RenameFileTool::class, ['from' => '/a', 'to' => '/b']],
    'send_command' => [Tools\SendCommandTool::class, ['command' => 'stop']],
    'power' => [Tools\PowerTool::class, ['signal' => 'start']],
    'create_backup' => [Tools\CreateBackupTool::class, []],
    'update_startup_variable' => [Tools\UpdateStartupVariableTool::class, ['key' => 'X', 'value' => 'y']],
]);

test('permissions are checked again when the tool runs', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::ControlConsole->value]);
    $context = context($user, $server);
    $tool = tool(Tools\SendCommandTool::class);
    expect($tool->allowed($context))->toBeTrue();

    // The permission is taken away after the model was offered the tool.
    Subuser::query()->where('user_id', $user->id)->update(['permissions' => [Permissions::FileRead->value]]);
    $fakes = wings();

    expect(fn (): ToolResult => $tool->execute(['command' => 'op attacker'], $context))->toThrow(ToolException::class, AbstractTool::DENIED);
    assertNoWingsCalls($fakes);
});

test('a power signal needs its own control permission', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::ControlStart->value]);
    $fakes = wings();
    $tool = tool(Tools\PowerTool::class);

    expect(fn (): ToolResult => $tool->execute(['signal' => 'stop'], context($user, $server)))->toThrow(ToolException::class, AbstractTool::DENIED)
        ->and(fn (): ToolResult => $tool->execute(['signal' => 'kill'], context($user, $server)))->toThrow(ToolException::class, AbstractTool::DENIED)
        ->and(fn (): ToolResult => $tool->execute(['signal' => 'explode'], context($user, $server)))->toThrow(ToolException::class, 'The signal must be one of');
    $fakes['power']->assertNothingSent();

    $result = $tool->execute(['signal' => 'start'], context($user, $server));
    expect($result->display)->toBe(['signal' => 'start']);
    $fakes['power']->assertSentTo('start', $server->uuid);
    $this->assertActivityActor('server:power.start', $user);
});

test('changing tools always need confirmation', function (string $class): void {
    [$user, $server] = $this->generateTestAccount();

    expect(tool($class)->needsConfirmation([], context($user, $server)))->toBeTrue();
})->with([
    Tools\WriteFileTool::class,
    Tools\DeleteFilesTool::class,
    Tools\RenameFileTool::class,
    Tools\SendCommandTool::class,
    Tools\PowerTool::class,
    Tools\CreateBackupTool::class,
    Tools\UpdateStartupVariableTool::class,
]);

test('reading tools run without confirmation', function (string $class): void {
    [$user, $server] = $this->generateTestAccount();

    expect(tool($class)->needsConfirmation([], context($user, $server)))->toBeFalse();
})->with([
    Tools\ServerStatusTool::class,
    Tools\ReadConsoleTool::class,
    Tools\ListFilesTool::class,
    Tools\SearchFilesTool::class,
    Tools\ListBackupsTool::class,
    Tools\ListSchedulesTool::class,
    Tools\GetStartupTool::class,
    Tools\GetNetworkTool::class,
]);

test('reading a file that may hold secrets needs confirmation', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $tool = tool(Tools\ReadFileTool::class);
    $context = context($user, $server);

    expect($tool->needsConfirmation(['path' => '/server.properties'], $context))->toBeFalse()
        ->and($tool->needsConfirmation(['path' => '/bot/.env'], $context))->toBeTrue()
        ->and($tool->needsConfirmation(['path' => '/plugins/../config/../.env'], $context))->toBeTrue()
        ->and($tool->needsConfirmation(['path' => '/ssl/server.KEY'], $context))->toBeTrue()
        ->and($tool->needsConfirmation(['path' => '/auth_token.txt'], $context))->toBeTrue()
        ->and($tool->needsConfirmation(['path' => ['/.env']], $context))->toBeTrue()
        ->and($tool->needsConfirmation([], $context))->toBeTrue();
});

test('a file is read through wings and its read is logged', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::FileReadContent->value]);
    $fakes = wings();
    $fakes['file']->content = "motd=Hello\nmax-players=20";

    $result = tool(Tools\ReadFileTool::class)->execute(['path' => 'server.properties'], context($user, $server));

    expect($result->content)->toBe("motd=Hello\nmax-players=20")
        ->and($result->display)->toBe(['path' => '/server.properties', 'bytes' => 25]);
    $fakes['file']->assertContentFetched('/server.properties');
    $this->assertActivityActor('server:file.read', $user);
});

test('binary files are not read', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $fakes = wings();
    $fakes['file']->content = "PK\x03\x04\0\0binary";

    expect(fn (): ToolResult => tool(Tools\ReadFileTool::class)->execute(['path' => '/world.zip'], context($user, $server)))
        ->toThrow(ToolException::class, 'not a text file');
});

test('file contents have addresses and node host names taken out and are cut to size', function (): void {
    [$user, $server] = $this->generateTestAccount();
    Node::query()->whereKey($server->node_id)->update(['fqdn' => 'wings-de1.example.net']);
    $fakes = wings();
    $fakes['file']->content = "server-ip=203.0.113.7\nproxy=[2001:db8::1]:25577\nnode=WINGS-DE1.example.net\n".str_repeat('a', 20_000);

    $result = tool(Tools\ReadFileTool::class)->execute(['path' => '/server.properties'], context($user, $server));

    expect($result->content)
        ->toStartWith("server-ip=[hidden-ip]\nproxy=[[hidden-ip]]:25577\nnode=[hidden-host]\n")
        ->not->toContain('203.0.113.7')
        ->not->toContain('2001:db8')
        ->not->toContain('wings-de1')
        ->toContain('[output truncated:')
        ->and(mb_strlen($result->content, '8bit'))->toBeLessThan(AbstractTool::MAX_CONTENT_BYTES + 100);
});

test('the console is read with colour codes and addresses removed', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::WebsocketConnect->value]);
    Node::query()->whereKey($server->node_id)->update(['fqdn' => 'node7.host.example']);
    $fakes = wings();
    $fakes['server']->logs = ["\e[33m[12:00:00] Starting server on 0.0.0.0:25565\e[0m", '[12:00:04] Steve joined from /198.51.100.23:51234 via node7.host.example'];

    $result = tool(Tools\ReadConsoleTool::class)->execute(['lines' => 500], context($user, $server));

    expect($result->content)->toBe("[12:00:00] Starting server on [hidden-ip]:25565\n[12:00:04] Steve joined from /[hidden-ip]:51234 via [hidden-host]")
        ->and($result->display)->toBe(['lines' => 2]);
    $fakes['server']->assertLogsRead(Tools\ReadConsoleTool::MAX_LINES);
});

test('a directory is listed', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::FileRead->value]);
    $fakes = wings();
    $fakes['file']->directory = [
        ['name' => 'plugins', 'file' => false, 'symlink' => false, 'size' => 4096, 'modified' => '2026-11-01T10:00:00Z'],
        ['name' => 'server.jar', 'file' => true, 'symlink' => false, 'size' => 1024, 'modified' => '2026-11-01T10:00:00Z'],
    ];

    $result = tool(Tools\ListFilesTool::class)->execute(['directory' => '/plugins/..'], context($user, $server));

    expect(json_decode($result->content, true))->toBe([
        'directory' => '/',
        'entries' => [
            ['name' => 'plugins', 'type' => 'directory', 'size' => null, 'modified' => '2026-11-01T10:00:00Z'],
            ['name' => 'server.jar', 'type' => 'file', 'size' => 1024, 'modified' => '2026-11-01T10:00:00Z'],
        ],
    ]);
    $fakes['file']->assertDirectoryListed('/');
});

test('files are found by name', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $fakes = wings();
    $fakes['file']->directory = [
        ['name' => 'config', 'file' => false],
        ['name' => 'paper.yml', 'file' => true, 'size' => 10],
    ];

    $result = tool(Tools\SearchFilesTool::class)->execute(['query' => '*.YML'], context($user, $server));
    $data = json_decode($result->content, true);

    // The fake returns the same listing for every folder, so /config/paper.yml is found as well.
    expect(array_column($data['matches'], 'path'))->toContain('/paper.yml', '/config/paper.yml')
        ->and(count($fakes['file']->callsFor('getDirectory')))->toBeLessThanOrEqual(Tools\SearchFilesTool::MAX_DIRECTORIES);
});

test('writing a new file needs file.create and an existing one file.update', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::FileCreate->value]);
    $fakes = wings();
    $fakes['file']->directory = [['name' => 'existing.txt', 'file' => true]];
    $tool = tool(Tools\WriteFileTool::class);

    expect(fn (): ToolResult => $tool->execute(['path' => '/existing.txt', 'content' => 'overwritten'], context($user, $server)))
        ->toThrow(ToolException::class, AbstractTool::DENIED);
    expect($fakes['file']->callsFor('putContent'))->toBeEmpty();

    $result = $tool->execute(['path' => '/new.txt', 'content' => 'hello'], context($user, $server));
    expect($result->display)->toBe(['path' => '/new.txt', 'bytes' => 5, 'created' => true]);
    $fakes['file']->assertContentPut('/new.txt', 'hello');
});

test('redaction placeholders are never written back into a file', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $fakes = wings();

    expect(fn (): ToolResult => tool(Tools\WriteFileTool::class)->execute(['path' => '/server.properties', 'content' => 'server-ip='.OutputRedactor::IP], context($user, $server)))
        ->toThrow(ToolException::class, 'placeholder');
    expect($fakes['file']->callsFor('putContent'))->toBeEmpty();
});

test('files are deleted only inside the given directory', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::FileDelete->value]);
    $fakes = wings();
    $tool = tool(Tools\DeleteFilesTool::class);

    expect(fn (): ToolResult => $tool->execute(['root' => '/plugins', 'files' => ['../server.jar']], context($user, $server)))->toThrow(ToolException::class, 'is not inside')
        ->and(fn (): ToolResult => $tool->execute(['files' => ['.']], context($user, $server)))->toThrow(ToolException::class, 'is not inside');

    $tool->execute(['root' => '/plugins', 'files' => ['Old.jar', 'cache/']], context($user, $server));
    $fakes['file']->assertFilesDeleted('/plugins', ['Old.jar', 'cache']);
});

test('a file is renamed', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::FileUpdate->value]);
    $fakes = wings();

    tool(Tools\RenameFileTool::class)->execute(['from' => '/plugins/a.jar', 'to' => 'plugins/disabled/a.jar'], context($user, $server));

    $fakes['file']->assertFilesRenamed('/', [['from' => 'plugins/a.jar', 'to' => 'plugins/disabled/a.jar']]);
});

test('a console command is sent', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::ControlConsole->value]);
    $fakes = wings();
    $tool = tool(Tools\SendCommandTool::class);

    expect(fn (): ToolResult => $tool->execute(['command' => "say a\nop me"], context($user, $server)))->toThrow(ToolException::class, 'single-line');

    $tool->execute(['command' => 'say Hello'], context($user, $server));
    $fakes['command']->assertSent('say Hello');
});

test('a backup is started', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::BackupCreate->value]);
    $server->update(['backup_limit' => 2]);
    $fakes = wings();

    $result = tool(Tools\CreateBackupTool::class)->execute(['name' => 'Before update'], context($user, $server));

    $backup = Backup::query()->where('server_id', $server->id)->sole();
    expect($backup->name)->toBe('Before update')
        ->and($backup->is_locked)->toBeFalse()
        ->and($result->display)->toBe(['uuid' => $backup->uuid, 'name' => 'Before update']);
    $fakes['backup']->assertBackedUp($backup->uuid);
});

test('the backup limit is reported as a tool error', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $server->update(['backup_limit' => 0]);
    wings();

    expect(fn (): ToolResult => tool(Tools\CreateBackupTool::class)->execute([], context($user, $server)))->toThrow(ToolException::class);
});

test('backups and schedules are listed', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::BackupRead->value, Permissions::ScheduleRead->value]);
    Backup::factory()->create(['server_id' => $server->id, 'name' => 'Nightly', 'bytes' => 10 * 1_048_576, 'is_successful' => true, 'completed_at' => now()]);
    Schedule::factory()->create(['server_id' => $server->id, 'name' => 'Restart', 'cron_minute' => '0', 'cron_hour' => '4', 'cron_day_of_month' => '*', 'cron_month' => '*', 'cron_day_of_week' => '*', 'is_active' => true]);

    $backups = json_decode(tool(Tools\ListBackupsTool::class)->execute([], context($user, $server))->content, true);
    $schedules = json_decode(tool(Tools\ListSchedulesTool::class)->execute([], context($user, $server))->content, true);

    expect($backups['backups'][0])->toMatchArray(['name' => 'Nightly', 'state' => 'completed', 'size_mib' => 10])
        ->and($schedules['schedules'][0])->toMatchArray(['name' => 'Restart', 'cron' => '0 4 * * *', 'active' => true]);
});

test('the startup settings never show the node address', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::StartupRead->value]);
    Allocation::query()->whereKey($server->allocation_id)->update(['ip' => '203.0.113.50']);
    $server->update(['startup' => 'java -jar server.jar --host {{SERVER_IP}} --port {{SERVER_PORT}}']);

    $result = tool(Tools\GetStartupTool::class)->execute([], context($user, $server));

    expect($result->content)->not->toContain('203.0.113.50')
        ->toContain('--host [hidden-ip] --port');
});

test('the network tool gives ports and the playit address but never node addresses', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::AllocationRead->value]);
    Allocation::query()->whereKey($server->allocation_id)->update(['ip' => '203.0.113.51', 'ip_alias' => 'mc.node-host.example']);
    ServerTunnel::query()->forceCreate(['server_id' => $server->id, 'allocation_id' => $server->allocation_id, 'status' => TunnelStatus::Active, 'address' => '147.185.221.20:41234']);

    $result = tool(Tools\GetNetworkTool::class)->execute([], context($user, $server));
    $data = json_decode($result->content, true);

    expect($data['public_address'])->toBe('147.185.221.20:41234')
        ->and($data['allocations'][0])->toHaveKeys(['port', 'primary', 'notes'])
        ->and($data['allocations'][0])->not->toHaveKey('ip')
        ->and($result->content)->not->toContain('203.0.113.51')->not->toContain('node-host');
});

test('a startup variable is changed under its own rules', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::StartupUpdate->value]);
    $egg = $this->cloneEggAndVariables($server->egg);
    $server->update(['egg_id' => $egg->id]);
    EggVariable::query()->where('egg_id', $egg->id)->delete();
    EggVariable::factory()->create(['egg_id' => $egg->id, 'env_variable' => 'VERSION', 'rules' => 'required|string|in:1.20,1.21', 'user_viewable' => true, 'user_editable' => true]);
    EggVariable::factory()->create(['egg_id' => $egg->id, 'env_variable' => 'LOCKED', 'rules' => 'nullable|string', 'user_viewable' => true, 'user_editable' => false]);
    EggVariable::factory()->create(['egg_id' => $egg->id, 'env_variable' => 'SECRET', 'rules' => 'nullable|string', 'user_viewable' => false, 'user_editable' => false]);
    $tool = tool(Tools\UpdateStartupVariableTool::class);
    $context = context($user, $server->refresh());

    expect(fn (): ToolResult => $tool->execute(['key' => 'VERSION', 'value' => '1.8'], $context))->toThrow(ToolException::class)
        ->and(fn (): ToolResult => $tool->execute(['key' => 'LOCKED', 'value' => 'x'], $context))->toThrow(ToolException::class, 'read-only')
        ->and(fn (): ToolResult => $tool->execute(['key' => 'SECRET', 'value' => 'x'], $context))->toThrow(ToolException::class, 'There is no startup variable');

    $tool->execute(['key' => 'VERSION', 'value' => '1.21'], $context);
    expect($server->serverVariables()->sole()->variable_value)->toBe('1.21');
    $this->assertActivityActor('server:startup.edit', $user);
});

test('the status can be read while the server is suspended, but nothing else runs', function (): void {
    [$user, $server] = $this->generateTestAccount();
    $server->update(['status' => Server::STATUS_SUSPENDED]);
    $fakes = wings();
    $fakes['server']->details = ['state' => 'offline', 'utilization' => ['memory_bytes' => 2 * 1_048_576]];

    $status = json_decode(tool(Tools\ServerStatusTool::class)->execute([], context($user, $server))->content, true);
    expect($status)->toMatchArray(['state' => 'offline', 'suspended' => true])
        ->and($status['resources']['memory_mib'])->toEqual(2);

    expect(fn (): ToolResult => tool(Tools\ListFilesTool::class)->execute([], context($user, $server)))->toThrow(ToolException::class, 'suspended');
    $fakes['file']->assertNothingHappened();
});

test('the redactor replaces any address literal but leaves times, versions and kept values', function (): void {
    expect((new OutputRedactor)->redact('from 192.0.2.1, ::1 and fe80::1%eth0 at 12:00:04 v1.2.3 std::cout ab:cd:ef:01:23:45'))
        ->toBe('from [hidden-ip], [hidden-ip] and [hidden-ip]%eth0 at 12:00:04 v1.2.3 std::cout ab:cd:ef:01:23:45')
        ->and((new OutputRedactor)->redact('join 147.185.221.20:41234 not 10.0.0.2', ['147.185.221.20:41234']))
        ->toBe('join 147.185.221.20:41234 not [hidden-ip]');
});
