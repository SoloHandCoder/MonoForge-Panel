<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Daemon\FileHasherTest;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Daemon\FileHasher;
use Pterodactyl\Tests\TestCase;

uses(TestCase::class);

const JAR = "PK\x03\x04 a jar's bytes";

function hasherServer(): Server
{
    $node = Node::factory()->make([
        'location_id' => 1,
        'fqdn' => 'wings.example.test',
        'scheme' => 'https',
        'daemonListen' => 8080,
        'daemon_token' => Crypt::encrypt(str_repeat('k', 64)),
    ])->setRelation('mounts', new Collection);

    return Server::factory()->make(['uuid' => 'server-one', 'owner_id' => 1, 'node_id' => 1, 'egg_id' => 1, 'allocation_id' => 1])
        ->setRelation('node', $node);
}

test('a file Wings shows is hashed from its contents', function (): void {
    Http::fake(['https://wings.example.test:8080/api/servers/server-one/files/contents*' => Http::response('plain text')]);

    $hashes = resolve(FileHasher::class)->hash(hasherServer(), 'server.properties', ['sha1'], 1024, bytesRead: $read);

    expect($hashes)->toBe(['sha1' => sha1('plain text')])->and($read)->toBe(10);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/download/file'));
});

test('a jar Wings will not show is hashed through a signed download link', function (): void {
    Http::fake([
        'https://wings.example.test:8080/api/servers/server-one/files/contents*' => Http::response(['error' => 'file is an archive, cannot view contents'], 400),
        'https://wings.example.test:8080/download/file*' => Http::response(JAR),
    ]);

    $hashes = resolve(FileHasher::class)->hash(hasherServer(), 'plugins/Example.jar', ['sha1', 'sha256'], 1024, bytesRead: $read);

    expect($hashes)->toBe(['sha1' => sha1(JAR), 'sha256' => hash('sha256', JAR)])->and($read)->toBe(mb_strlen(JAR, '8bit'));

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/download/file')) {
            return false;
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $token = (new Parser(new JoseEncoder))->parse((string) $query['token']);

        // The link is for exactly this file of this server, and it is not sent with the node's token.
        return $token instanceof UnencryptedToken
            && $token->claims()->get('file_path') === '/plugins/Example.jar'
            && $token->claims()->get('server_uuid') === 'server-one'
            && ! $request->hasHeader('Authorization');
    });
});

test('the size cap also applies to the download link', function (): void {
    Http::fake([
        'https://wings.example.test:8080/api/servers/server-one/files/contents*' => Http::response(['error' => 'file is an archive, cannot view contents'], 400),
        'https://wings.example.test:8080/download/file*' => Http::response(str_repeat('x', 2048)),
    ]);

    resolve(FileHasher::class)->hash(hasherServer(), 'big.jar', ['sha1'], 1024);
})->throws(FileSizeTooLargeException::class);

test('other Wings errors are not retried through a download link', function (): void {
    Http::fake([
        'https://wings.example.test:8080/api/servers/server-one/files/contents*' => Http::response(['error' => 'file not found'], 404),
        'https://wings.example.test:8080/download/file*' => Http::response(JAR),
    ]);

    try {
        resolve(FileHasher::class)->hash(hasherServer(), 'missing.jar', ['sha1'], 1024);
        $this->fail('Expected a Wings error.');
    } catch (DaemonConnectionException) {
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/download/file'));
    }
});
