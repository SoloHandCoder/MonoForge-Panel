<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Query\StreamQueryConnectorTest;

use Pterodactyl\Services\Query\MinecraftJavaPing;
use Pterodactyl\Services\Query\StreamQueryConnection;
use Pterodactyl\Services\Query\StreamQueryConnector;
use Pterodactyl\Tests\Support\Fakes\FakeMinecraftServer;

/**
 * A listening socket on a free local port.
 *
 * @return array{resource, int}
 */
function listener(): array
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
    expect($server)->not->toBeFalse();
    $port = (int) mb_substr((string) stream_socket_get_name($server, false), mb_strrpos((string) stream_socket_get_name($server, false), ':') + 1);

    return [$server, $port];
}

test('talks to a real socket and reads a status answer', function (): void {
    [$server, $port] = listener();

    $connection = (new StreamQueryConnector)->open('127.0.0.1', $port, 2.0);
    expect($connection)->toBeInstanceOf(StreamQueryConnection::class);

    $peer = stream_socket_accept($server, 1);
    $response = FakeMinecraftServer::response('{"players":{"online":4,"max":10},"description":"Hi"}');
    fwrite($peer, $response);

    expect($connection->write('hello'))->toBeTrue()
        ->and(fread($peer, 5))->toBe('hello')
        ->and($connection->read(mb_strlen($response, '8bit')))->toBe($response);

    $packet = mb_substr($response, 1, null, '8bit');
    expect(MinecraftJavaPing::parse($packet)?->online)->toBe(4);

    $connection->close();
    expect($connection->write('x'))->toBeFalse();
    fclose($peer);
    fclose($server);
});

test('gives up at the deadline when the server sends nothing', function (): void {
    [$server, $port] = listener();
    $connection = (new StreamQueryConnector)->open('127.0.0.1', $port, 0.3);
    $peer = stream_socket_accept($server, 1);

    $started = microtime(true);
    expect($connection?->read(10))->toBeNull()
        ->and(microtime(true) - $started)->toBeLessThan(1.5);

    fclose($peer);
    fclose($server);
});

test('answers null when nothing listens', function (): void {
    [$server, $port] = listener();
    fclose($server);

    expect((new StreamQueryConnector)->open('127.0.0.1', $port, 0.5))->toBeNull();
});
