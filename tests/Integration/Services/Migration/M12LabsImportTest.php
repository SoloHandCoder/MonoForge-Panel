<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Migration\M12LabsImportTest;

use Illuminate\Database\Connection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Services\Migration\Cell;
use Pterodactyl\Services\Migration\ImportContext;
use Pterodactyl\Services\Migration\ImportSummary;
use Pterodactyl\Services\Migration\PanelImportService;
use Pterodactyl\Services\Migration\Profiles\M12LabsProfile;
use Pterodactyl\Services\Migration\TablePlan;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

uses(IntegrationTestCase::class, DatabaseTransactions::class);

const SOURCE_DATABASE = 'm12labs_import_source';

const NODE_UUID = 'b0a1c2d3-e4f5-4a6b-8c7d-9e0f1a2b3c4d';

const SERVER_UUID = 'c1b2a3d4-e5f6-4b7a-9c8d-0e1f2a3b4c5d';

/**
 * The m12labs panel's APP_KEY: 32 raw bytes, as AES-256-CBC needs. Real keys are
 * random bytes, not text, so this one includes bytes that are not valid UTF-8.
 */
function sourceKey(): string
{
    return (string) hex2bin('c328a0a1e228a1f0288cbcf09028bc80fffe0102030405060708090a0b0c0d0e');
}

beforeEach(function (): void {
    seedSourcePanel(buildSourceDatabase(), new Encrypter(sourceKey(), 'AES-256-CBC'));
    emptyTargetTables();
});

afterEach(function (): void {
    adminConnection()->statement('DROP DATABASE IF EXISTS `'.SOURCE_DATABASE.'`');
    DB::purge('m12labs_source');
});

test('imports an m12labs panel and maps what changed', function (): void {
    $summary = runImport(source(), sourceKey());

    // Nodes: public ports become the one port per service, locations are created.
    $node = DB::table('nodes')->where('uuid', NODE_UUID)->sole();
    expect($node->daemonListen)->toEqual(443)
        ->and($node->daemonSFTP)->toEqual(2222)
        ->and($node->daemonBase)->toBe('/var/lib/pterodactyl/volumes')
        ->and(DB::table('locations')->where('id', $node->location_id)->value('short'))->toBe('m12labs')
        ->and(decrypt(Cell::string($node->daemon_token)))->toBe('node-secret-token');

    // Servers: OOM killer enabled in m12labs means not disabled here.
    $server = DB::table('servers')->where('uuid', SERVER_UUID)->sole();
    expect($server->oom_disabled)->toEqual(0)
        ->and($server->description)->toBe('');

    // Database hosts are now linked from the host side.
    expect(DB::table('database_hosts')->where('id', 1)->value('node_id'))->toEqual(1)
        ->and(decrypt(Cell::string(DB::table('database_hosts')->where('id', 1)->value('password'))))->toBe('host-password');

    // Users: names are optional here, missing languages fall back to English.
    expect(DB::table('users')->where('username', 'staff')->value('language'))->toBe('en');

    // Only account and application API keys are carried over.
    expect(DB::table('api_keys')->pluck('id')->all())->toEqual([1]);

    // Duplicate subusers collapse to one.
    expect(DB::table('subusers')->count())->toBe(1);

    // Eggs are tagged from their nests.
    expect(DB::table('taggables')->where('taggable_id', 1)->exists())->toBeTrue();

    expect($summary->skipped)->toHaveKey('products')
        ->and($summary->filtered)->toBe(['api_keys' => 1, 'subusers' => 1])
        ->and($summary->warnings)->toContain(
            '1 user(s) had admin access through an m12labs access profile and will be regular users in MonoForge: staff. Make them root administrators afterwards if they still need admin access.',
        );
});

test('a dry run writes nothing', function (): void {
    runImport(source(), sourceKey(), dryRun: true);

    expect(DB::table('users')->count())->toBe(0)
        ->and(DB::table('nodes')->count())->toBe(0)
        ->and(DB::table('servers')->count())->toBe(0);
});

test('a wrong source APP_KEY is refused before anything is written', function (): void {
    expect(fn (): ImportSummary => runImport(source(), Encrypter::generateKey('AES-256-CBC')))
        ->toThrow(RuntimeException::class, 'Could not decrypt');

    expect(DB::table('users')->count())->toBe(0);
});

test('an import into a panel that already has data is refused', function (): void {
    DB::table('nests')->insert(['id' => 99, 'uuid' => Uuid::uuid4()->toString(), 'author' => 'a@example.test', 'name' => 'Existing']);

    expect(fn (): ImportSummary => runImport(source(), sourceKey()))
        ->toThrow(RuntimeException::class, 'already contain data: nests');
});

test('the artisan command reads a base64 APP_KEY and runs a dry run', function (): void {
    $this->artisan('p:migrate:m12labs', [
        '--host' => config('database.connections.mysql.host'),
        '--port' => config('database.connections.mysql.port'),
        '--database' => SOURCE_DATABASE,
        '--username' => config('database.connections.mysql.username'),
        '--password' => config('database.connections.mysql.password'),
        '--source-key' => 'base64:'.base64_encode(sourceKey()),
        '--dry-run' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Dry run finished')
        ->assertExitCode(0);

    expect(DB::table('users')->count())->toBe(0);
});

function runImport(Connection $source, string $sourceKey, bool $dryRun = false): ImportSummary
{
    $context = new ImportContext(new Encrypter($sourceKey, 'AES-256-CBC'), resolve(Encrypter::class));

    return (new PanelImportService(DB::connection()))->import(new M12LabsProfile, $source, $context, [TablePlan::GROUP_CORE], $dryRun);
}

function source(): Connection
{
    return DB::connection('m12labs_source');
}

/**
 * A separate connection for the source schema. Its DDL commits on its own
 * connection, leaving the test's transaction on the default one untouched.
 */
function adminConnection(): Connection
{
    Config::set('database.connections.m12labs_admin', config('database.connections.mysql'));

    return DB::connection('m12labs_admin');
}

/**
 * Recreate the m12labs schema for the tables the importer reads: start from
 * this panel's tables and apply the changes m12labs made to them.
 */
function buildSourceDatabase(): Connection
{
    $admin = adminConnection();
    $target = config()->string('database.connections.mysql.database');
    $source = SOURCE_DATABASE;

    $admin->statement("DROP DATABASE IF EXISTS `{$source}`");
    $admin->statement("CREATE DATABASE `{$source}`");

    foreach (['users', 'api_keys', 'nests', 'eggs', 'database_hosts', 'nodes', 'allocations', 'servers', 'subusers'] as $table) {
        $admin->statement("CREATE TABLE `{$source}`.`{$table}` LIKE `{$target}`.`{$table}`");
    }

    $admin->statement("ALTER TABLE `{$source}`.`users` DROP COLUMN name_first, DROP COLUMN name_last, MODIFY language CHAR(5) NULL, ADD admin_role_id INT UNSIGNED NULL, ADD stripe_id VARCHAR(191) NULL");
    $admin->statement("ALTER TABLE `{$source}`.`api_keys` ADD acl_enforced TINYINT(1) NULL");
    $admin->statement("ALTER TABLE `{$source}`.`eggs` DROP COLUMN config_logs");
    $admin->statement("ALTER TABLE `{$source}`.`database_hosts` DROP COLUMN node_id");
    $admin->statement("ALTER TABLE `{$source}`.`nodes`
        DROP COLUMN location_id,
        CHANGE daemonListen listen_port_http INT UNSIGNED NOT NULL DEFAULT 8080,
        CHANGE daemonSFTP listen_port_sftp INT UNSIGNED NOT NULL DEFAULT 2022,
        CHANGE daemonBase daemon_base VARCHAR(191) NOT NULL DEFAULT '/home/daemon-files',
        ADD public_port_http INT UNSIGNED NOT NULL DEFAULT 8080,
        ADD public_port_sftp INT UNSIGNED NOT NULL DEFAULT 2022,
        ADD database_host_id INT UNSIGNED NULL,
        ADD wings_type VARCHAR(20) NOT NULL DEFAULT 'default'");
    $admin->statement("ALTER TABLE `{$source}`.`servers` CHANGE oom_disabled oom_killer TINYINT UNSIGNED NOT NULL DEFAULT 1, MODIFY description TEXT NULL, ADD billing_amount DECIMAL(10, 2) NULL");
    $admin->statement("ALTER TABLE `{$source}`.`subusers` DROP INDEX subusers_server_id_user_id_unique, ADD disabled_extensions TEXT NULL");
    $admin->statement("CREATE TABLE `{$source}`.`products` (id INT UNSIGNED PRIMARY KEY, name VARCHAR(191) NOT NULL)");

    Config::set('database.connections.m12labs_source', [...config('database.connections.mysql'), 'database' => $source]);
    DB::purge('m12labs_source');

    return DB::connection('m12labs_source');
}

function seedSourcePanel(Connection $source, Encrypter $key): void
{
    $now = now()->toDateTimeString();

    $source->table('users')->insert([
        ['id' => 1, 'uuid' => Uuid::uuid4()->toString(), 'username' => 'owner', 'email' => 'owner@example.test', 'password' => 'hash', 'language' => 'de', 'root_admin' => 1, 'use_totp' => 0, 'admin_role_id' => 1, 'stripe_id' => 'cus_1', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 2, 'uuid' => Uuid::uuid4()->toString(), 'username' => 'staff', 'email' => 'staff@example.test', 'password' => 'hash', 'language' => null, 'root_admin' => 0, 'use_totp' => 0, 'admin_role_id' => 3, 'stripe_id' => null, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $source->table('api_keys')->insert([
        ['id' => 1, 'user_id' => 1, 'key_type' => 1, 'identifier' => 'ptlc_aaaaaaaaaaa', 'token' => $key->encrypt('account-token'), 'memo' => 'account', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 2, 'user_id' => 1, 'key_type' => 3, 'identifier' => 'ptld_bbbbbbbbbbb', 'token' => $key->encrypt('daemon-token'), 'memo' => 'daemon', 'created_at' => $now, 'updated_at' => $now],
    ]);

    $source->table('nests')->insert(['id' => 1, 'uuid' => Uuid::uuid4()->toString(), 'author' => 'support@example.test', 'name' => 'Minecraft', 'created_at' => $now, 'updated_at' => $now]);

    $source->table('eggs')->insert([
        'id' => 1,
        'uuid' => Uuid::uuid4()->toString(),
        'nest_id' => 1,
        'author' => 'support@example.test',
        'name' => 'Paper',
        'description' => 'Paper server',
        'docker_images' => json_encode(['Java' => 'ghcr.io/pterodactyl/yolks:java_21']),
        'features' => json_encode(['eula']),
        'file_denylist' => json_encode([]),
        'startup' => 'java -jar server.jar',
        'config_stop' => 'stop',
        'config_startup' => '{"done": ["Done"]}',
        'config_files' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $source->table('database_hosts')->insert(['id' => 1, 'name' => 'db', 'host' => '127.0.0.1', 'port' => 3306, 'username' => 'root', 'password' => $key->encrypt('host-password'), 'created_at' => $now, 'updated_at' => $now]);

    $source->table('nodes')->insert([
        'id' => 1,
        'uuid' => NODE_UUID,
        'public' => 1,
        'name' => 'Node One',
        'fqdn' => 'node.example.test',
        'scheme' => 'https',
        'behind_proxy' => 1,
        'memory' => 8192,
        'disk' => 102400,
        'daemon_token_id' => 'abcdefghijklmnop',
        'daemon_token' => $key->encrypt('node-secret-token'),
        'listen_port_http' => 8080,
        'listen_port_sftp' => 2022,
        'public_port_http' => 443,
        'public_port_sftp' => 2222,
        'daemon_base' => '/var/lib/pterodactyl/volumes',
        'database_host_id' => 1,
        'wings_type' => 'calagopus',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $source->table('allocations')->insert(['id' => 1, 'node_id' => 1, 'ip' => '0.0.0.0', 'port' => 25565, 'server_id' => 1, 'created_at' => $now, 'updated_at' => $now]);

    $source->table('servers')->insert([
        'id' => 1,
        'uuid' => SERVER_UUID,
        'uuidShort' => 'c1b2a3d4',
        'node_id' => 1,
        'name' => 'Survival',
        'description' => null,
        'owner_id' => 2,
        'memory' => 2048,
        'swap' => 0,
        'disk' => 10240,
        'io' => 500,
        'cpu' => 200,
        'oom_killer' => 1,
        'allocation_id' => 1,
        'nest_id' => 1,
        'egg_id' => 1,
        'startup' => 'java -jar server.jar',
        'image' => 'ghcr.io/pterodactyl/yolks:java_21',
        'billing_amount' => 4.99,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $source->table('subusers')->insert([
        ['id' => 1, 'user_id' => 1, 'server_id' => 1, 'permissions' => json_encode(['control.console']), 'created_at' => $now, 'updated_at' => $now],
        ['id' => 2, 'user_id' => 1, 'server_id' => 1, 'permissions' => json_encode(['control.start']), 'created_at' => $now, 'updated_at' => $now],
    ]);

    $source->table('products')->insert(['id' => 1, 'name' => 'Starter']);
}

/**
 * The test database is seeded with eggs and tags; the importer only runs into
 * empty tables. Clear them inside the test transaction, which rolls back.
 */
function emptyTargetTables(): void
{
    $tables = array_map(fn (TablePlan $plan): string => $plan->table, (new M12LabsProfile)->tables());

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    foreach ([...$tables, 'taggables', 'tags', 'locations'] as $table) {
        DB::table($table)->delete();
    }

    DB::statement('SET FOREIGN_KEY_CHECKS=1');
}
