<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Tunnels\TunnelProfileTest;

use Illuminate\Database\Eloquent\Collection;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Tag;
use Pterodactyl\Services\Tunnels\PlayitClient;
use Pterodactyl\Services\Tunnels\TunnelProfile;
use Pterodactyl\Tests\TestCase;

uses(TestCase::class);

/** @param  list<string>  $tags */
function egg(string $name, array $tags = []): Egg
{
    $egg = new Egg(['name' => $name]);

    return $egg->setRelation('tags', new Collection(array_map(fn (string $slug): Tag => (new Tag)->forceFill(['slug' => $slug]), $tags)));
}

test('picks the playit tunnel type from the egg', function (string $name, array $tags, ?string $type, string $protocol): void {
    expect(TunnelProfile::forEgg(egg($name, $tags)))->toBe(['tunnel_type' => $type, 'port_type' => $protocol]);
})->with([
    'paper' => ['Paper', ['minecraft'], 'minecraft-java', 'tcp'],
    'tagged only' => ['Some Server Jar', ['minecraft'], 'minecraft-java', 'tcp'],
    'bedrock beats the minecraft tag' => ['Vanilla Bedrock', ['minecraft'], 'minecraft-bedrock', 'udp'],
    'terraria' => ['tModLoader', [], 'terraria', 'tcp'],
    'valheim' => ['Valheim Plus', [], 'valheim', 'udp'],
    'rust' => ['Rust', ['rust'], 'rust', 'udp'],
    'not trust' => ['Trust Me', [], null, 'both'],
    'unknown' => ['Garrys Mod', ['srcds'], null, 'both'],
]);

test('no egg gets a plain tunnel for both protocols', function (): void {
    expect(TunnelProfile::forEgg(null))->toBe(['tunnel_type' => null, 'port_type' => 'both']);
});

test('reads the address players connect to from an allocated tunnel', function (array $tunnel, ?string $address): void {
    expect(PlayitClient::addressOf($tunnel))->toBe($address);
})->with([
    'pending' => [['alloc' => ['status' => 'pending']], null],
    'disabled' => [['alloc' => ['status' => 'disabled', 'data' => ['reason' => 'requires-premium']]], null],
    'host and port' => [['tunnel_type' => null, 'alloc' => ['status' => 'allocated', 'data' => ['assigned_domain' => 'abc.ply.gg', 'port_start' => 1234]]], 'abc.ply.gg:1234'],
    'java srv' => [['tunnel_type' => 'minecraft-java', 'alloc' => ['status' => 'allocated', 'data' => ['assigned_domain' => 'abc.ply.gg', 'assigned_srv' => 'xyz.joinmc.link', 'port_start' => 1234]]], 'xyz.joinmc.link'],
    'srv ignored for other games' => [['tunnel_type' => 'terraria', 'alloc' => ['status' => 'allocated', 'data' => ['assigned_domain' => 'abc.ply.gg', 'assigned_srv' => 'x.example', 'port_start' => 7777]]], 'abc.ply.gg:7777'],
    'ip hostname fallback' => [['alloc' => ['status' => 'allocated', 'data' => ['assigned_domain' => '', 'ip_hostname' => '147.185.221.1', 'port_start' => 5]]], '147.185.221.1:5'],
]);
