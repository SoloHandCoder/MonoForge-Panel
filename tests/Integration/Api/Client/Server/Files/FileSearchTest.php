<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\Server\Files\FileSearchTest;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Actions\Files\SearchFileNames;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use RuntimeException;

uses(ClientApiIntegrationTestCase::class);

/** A directory tree served from memory, recording every directory listed. */
final class TreeLister implements ListsDirectories
{
    /** @var list<string> */
    public array $listed = [];

    /** @param array<string, list<array{name: string, file?: bool, symlink?: bool, size?: int}>> $tree */
    public function __construct(private array $tree) {}

    public function list(Server $server, string $directory = '/'): array
    {
        $this->listed[] = $directory;

        throw_unless(array_key_exists($directory, $this->tree), DaemonConnectionException::class, new RuntimeException('missing'));

        return $this->tree[$directory];
    }
}

function tree(TreeLister $lister): TreeLister
{
    app()->instance(ListsDirectories::class, $lister);

    return $lister;
}

function minecraftTree(): TreeLister
{
    return tree(new TreeLister([
        '/' => [
            ['name' => 'server.properties', 'file' => true, 'size' => 1200],
            ['name' => 'plugins', 'file' => false],
            ['name' => 'node_modules', 'file' => false],
            ['name' => 'world-link', 'file' => false, 'symlink' => true],
        ],
        '/plugins' => [
            ['name' => 'Essentials', 'file' => false],
            ['name' => 'config.yml', 'file' => true, 'size' => 300],
        ],
        '/plugins/Essentials' => [
            ['name' => 'config.yml', 'file' => true, 'size' => 9000],
            ['name' => 'messages.properties', 'file' => true, 'size' => 50],
        ],
        '/node_modules' => [['name' => 'config.yml', 'file' => true, 'size' => 1]],
    ]));
}

beforeEach(fn () => Cache::flush());

test('search requires authentication and the file read permission', function (): void {
    [, $server] = $this->generateTestAccount();
    $this->getJson($this->link($server, '/files/search?query=config'))->assertUnauthorized();

    [$user, $server] = $this->generateTestAccount([Permissions::ControlConsole->value]);
    $this->actingAs($user)->getJson($this->link($server, '/files/search?query=config'))->assertForbidden();
});

test('search requires a query', function (): void {
    [$user, $server] = $this->generateTestAccount([Permissions::FileRead->value]);

    $this->actingAs($user)->getJson($this->link($server, '/files/search'))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', 'query');
});

test('search finds files by path, best matches first', function (): void {
    minecraftTree();
    [$user, $server] = $this->generateTestAccount([Permissions::FileRead->value]);

    $this->actingAs($user)->getJson($this->link($server, '/files/search?query=config'))
        ->assertOk()
        ->assertJsonPath('object', 'list')
        ->assertJsonPath('meta.truncated', false)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.object', 'file_search_result')
        ->assertJsonPath('data.0.attributes', ['path' => '/plugins/config.yml', 'name' => 'config.yml', 'is_file' => true, 'size' => 300])
        ->assertJsonPath('data.1.attributes.path', '/plugins/Essentials/config.yml');
});

test('every word of the query must appear in the path', function (): void {
    minecraftTree();
    [$user, $server] = $this->generateTestAccount([Permissions::FileRead->value]);

    $this->actingAs($user)->getJson($this->link($server, '/files/search?query='.rawurlencode('essentials CONFIG')))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.path', '/plugins/Essentials/config.yml');
});

test('only files are returned, a name match before a path match', function (): void {
    minecraftTree();
    [$user, $server] = $this->generateTestAccount([Permissions::FileRead->value]);

    $this->actingAs($user)->getJson($this->link($server, '/files/search?query=ess'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.attributes.path', '/plugins/Essentials/messages.properties')
        ->assertJsonPath('data.1.attributes.path', '/plugins/Essentials/config.yml');
});

test('search skips large folders and does not follow symbolic links', function (): void {
    $lister = minecraftTree();
    [$user, $server] = $this->generateTestAccount([Permissions::FileRead->value]);

    $this->actingAs($user)->getJson($this->link($server, '/files/search?query=config'))->assertOk();

    expect($lister->listed)->toBe(['/', '/plugins', '/plugins/Essentials']);
});

test('search starts below the given directory', function (): void {
    $lister = minecraftTree();
    [$user, $server] = $this->generateTestAccount([Permissions::FileRead->value]);

    $this->actingAs($user)->getJson($this->link($server, '/files/search?query=config&directory='.rawurlencode('/plugins/../plugins/Essentials')))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.path', '/plugins/Essentials/config.yml');

    expect($lister->listed)->toBe(['/plugins/Essentials']);
});

test('the walk is cached, so typing never lists a directory twice', function (): void {
    $lister = minecraftTree();
    [$user, $server] = $this->generateTestAccount([Permissions::FileRead->value]);

    $this->actingAs($user)->getJson($this->link($server, '/files/search?query=con'))->assertOk();
    $this->actingAs($user)->getJson($this->link($server, '/files/search?query=conf'))->assertOk()->assertJsonCount(2, 'data');

    expect($lister->listed)->toBe(['/', '/plugins', '/plugins/Essentials']);
});

test('the walk stops at its directory limit and reports it', function (): void {
    $tree = ['/' => []];
    for ($i = 0; $i < SearchFileNames::MAX_DIRECTORIES + 10; $i++) {
        $tree['/'][] = ['name' => "dir{$i}", 'file' => false];
        $tree["/dir{$i}"] = [['name' => "file{$i}.txt", 'file' => true, 'size' => 1]];
    }

    $lister = tree(new TreeLister($tree));
    [$user, $server] = $this->generateTestAccount([Permissions::FileRead->value]);

    $this->actingAs($user)->getJson($this->link($server, '/files/search?query=file'))
        ->assertOk()
        ->assertJsonPath('meta.truncated', true);

    expect($lister->listed)->toHaveCount(SearchFileNames::MAX_DIRECTORIES);
});

test('the walk stops at its depth limit', function (): void {
    $tree = [];
    $path = '';
    for ($depth = 0; $depth <= SearchFileNames::MAX_DEPTH + 2; $depth++) {
        $tree[$path === '' ? '/' : $path] = [['name' => 'deeper', 'file' => false], ['name' => "deep{$depth}.txt", 'file' => true]];
        $path .= '/deeper';
    }

    $lister = tree(new TreeLister($tree));
    [$user, $server] = $this->generateTestAccount([Permissions::FileRead->value]);

    $this->actingAs($user)->getJson($this->link($server, '/files/search?query=deep'))
        ->assertOk()
        ->assertJsonCount(SearchFileNames::MAX_DEPTH, 'data')
        ->assertJsonPath('meta.truncated', true);

    expect($lister->listed)->toHaveCount(SearchFileNames::MAX_DEPTH);
});

test('results are capped', function (): void {
    $tree = ['/' => []];
    for ($i = 0; $i < SearchFileNames::MAX_RESULTS + 5; $i++) {
        $tree['/'][] = ['name' => "log{$i}.txt", 'file' => true, 'size' => 1];
    }

    tree(new TreeLister($tree));
    [$user, $server] = $this->generateTestAccount([Permissions::FileRead->value]);

    $this->actingAs($user)->getJson($this->link($server, '/files/search?query=log'))
        ->assertOk()
        ->assertJsonCount(SearchFileNames::MAX_RESULTS, 'data')
        ->assertJsonPath('meta.truncated', true);
});

test('a missing starting directory is an error', function (): void {
    tree(new TreeLister([]));
    [$user, $server] = $this->generateTestAccount([Permissions::FileRead->value]);

    $this->actingAs($user)->getJson($this->link($server, '/files/search?query=x&directory=/nope'))->assertServerError();
});
