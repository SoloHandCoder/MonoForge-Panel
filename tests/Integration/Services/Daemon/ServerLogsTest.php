<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Daemon\ServerLogsTest;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\TestCase;

uses(TestCase::class);

function logServer(): Server
{
    $node = Node::factory()->make([
        'location_id' => 1,
        'fqdn' => 'wings.example.test',
        'scheme' => 'https',
        'daemonListen' => 8080,
        'daemon_token' => Crypt::encrypt('token'),
    ])->setRelation('mounts', new Collection);

    return Server::factory()->make([
        'uuid' => 'server-one',
        'owner_id' => 1,
        'node_id' => 1,
        'egg_id' => 1,
        'allocation_id' => 1,
    ])->setRelation('node', $node);
}

test('logs are read from the JSON response of Pterodactyl Wings', function (): void {
    Http::fake(['https://wings.example.test:8080/api/servers/server-one/logs*' => Http::response(['data' => ['one', 'two']])]);

    expect(Daemon::server(logServer())->logs(50))->toBe(['one', 'two']);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'size=50') && str_contains($request->url(), 'lines=50'));
});

test('logs are read from the plain text response of Calagopus Wings', function (): void {
    Http::fake(['https://wings.example.test:8080/api/servers/server-one/logs*' => Http::response(
        "[12:00:00] Starting server\r\n[12:00:04] Done\n",
        200,
        ['Content-Type' => 'text/plain; charset=utf-8'],
    )]);

    expect(Daemon::server(logServer())->logs(100))->toBe(['[12:00:00] Starting server', '[12:00:04] Done']);
});

test('plain text logs are trimmed to the requested line count', function (): void {
    Http::fake(['https://wings.example.test:8080/api/servers/server-one/logs*' => Http::response(
        "one\ntwo\nthree\n",
        200,
        ['Content-Type' => 'text/plain'],
    )]);

    expect(Daemon::server(logServer())->logs(2))->toBe(['two', 'three']);
});

test('an empty plain text log returns no lines', function (): void {
    Http::fake(['https://wings.example.test:8080/api/servers/server-one/logs*' => Http::response('', 200, ['Content-Type' => 'text/plain'])]);

    expect(Daemon::server(logServer())->logs(100))->toBe([]);
});
