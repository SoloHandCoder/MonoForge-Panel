<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Query\MinecraftJavaPingTest;

use Pterodactyl\Services\Query\MinecraftJavaPing;
use Pterodactyl\Services\Query\QueryStatus;
use Pterodactyl\Tests\Support\Fakes\FakeMinecraftServer;

test('encodes VarInts like the Minecraft protocol', function (int $value, string $hex): void {
    expect(bin2hex(MinecraftJavaPing::varInt($value)))->toBe($hex);
})->with([
    [0, '00'],
    [1, '01'],
    [127, '7f'],
    [128, '8001'],
    [255, 'ff01'],
    [25565, 'ddc701'],
    [2147483647, 'ffffffff07'],
    [-1, 'ffffffff0f'],
]);

test('sends a handshake into the status state and a status request', function (): void {
    $server = new FakeMinecraftServer;

    (new MinecraftJavaPing($server))->ping('mc.example.test', 25565);

    $host = 'mc.example.test';
    $handshake = "\x00".hex2bin('ffffffff0f').chr(mb_strlen($host, '8bit')).$host.pack('n', 25565)."\x01";
    expect($server->connections)->toBe([['host' => 'mc.example.test', 'port' => 25565, 'timeout' => 2.0]])
        ->and(bin2hex($server->written[0]))->toBe(bin2hex(chr(mb_strlen($handshake, '8bit')).$handshake."\x01\x00"));
});

test('reads players, version and a plain motd from a chat component', function (): void {
    $server = new FakeMinecraftServer;
    $server->online(3, ['Notch', 'jeb_', '.BedrockSteve', '§aWelcome to the server!', 'Notch']);
    $server->status['description'] = ['text' => '§6Mono', 'extra' => [['text' => 'Forge', 'bold' => true], ' §rSurvival']];

    $status = (new MinecraftJavaPing($server))->ping('127.0.0.1', 25565);

    expect($status)->toBeInstanceOf(QueryStatus::class)
        ->online->toBe(3)
        ->max->toBe(20)
        ->motd->toBe('MonoForge Survival')
        ->version->toBe('Paper 1.21.4')
        // The decorative sample line and the duplicate are dropped.
        ->players->toBe(['Notch', 'jeb_', '.BedrockSteve']);
});

test('an empty server has no players and a missing motd is null', function (): void {
    $server = new FakeMinecraftServer;
    $server->status = ['version' => ['name' => '1.20.1'], 'players' => ['max' => 10, 'online' => 0], 'description' => ['text' => '']];

    expect((new MinecraftJavaPing($server))->ping('127.0.0.1', 25565))
        ->online->toBe(0)
        ->motd->toBeNull()
        ->players->toBe([]);
});

test('answers null when the server does not speak Server List Ping', function (?string $raw, bool $accepts): void {
    $server = new FakeMinecraftServer;
    $server->accepts = $accepts;
    $server->raw = $raw;

    expect((new MinecraftJavaPing($server))->ping('127.0.0.1', 25565))->toBeNull();
})->with([
    'connection refused' => [null, false],
    'nothing comes back' => ['', true],
    'a truncated packet' => [mb_substr(FakeMinecraftServer::response('{"players":{"online":1,"max":2}}'), 0, 10, '8bit'), true],
    'another packet id' => [MinecraftJavaPing::varInt(3)."\x01\x01x", true],
    'not JSON' => [FakeMinecraftServer::response('hello there'), true],
    'no player counts' => [FakeMinecraftServer::response('{"version":{"name":"1.21"}}'), true],
    'a huge length' => [MinecraftJavaPing::varInt(10_000_000), true],
    'an HTTP server' => ["HTTP/1.1 400 Bad Request\r\n\r\n", true],
]);
