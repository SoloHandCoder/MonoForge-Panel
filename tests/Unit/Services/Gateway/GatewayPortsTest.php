<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Unit\Services\Gateway\GatewayPortsTest;

use Pterodactyl\Services\Gateway\GameGatewayService;
use Pterodactyl\Services\Gateway\GatewayHost;
use Pterodactyl\Services\Gateway\GatewayPorts;
use Pterodactyl\Services\Servers\ServerAddress;

test('port lists follow the syntax and limits of gateway.sh', function (): void {
    expect(GatewayPorts::error('25565-25700'))->toBeNull()
        ->and(GatewayPorts::error(' 25565-25700 , 7777 '))->toBeNull()
        ->and(GatewayPorts::error('1024,2000,3000,4000,5000,6000,65535'))->toBeNull()
        ->and(GatewayPorts::error('1,2,3,4,5,6,7,8'))->not->toBeNull()
        ->and(GatewayPorts::error('22'))->toContain('SSH')
        ->and(GatewayPorts::error('51800-51900'))->toContain('WireGuard')
        ->and(GatewayPorts::error('1023'))->not->toBeNull()
        ->and(GatewayPorts::error(''))->not->toBeNull()
        ->and(GatewayPorts::error('25565:25700'))->not->toBeNull();

    expect(GatewayPorts::parse('25565-25700, 7777'))->toBe([[25565, 25700], [7777, 7777]])
        ->and(GatewayPorts::parse('22'))->toBe([])
        ->and(GatewayPorts::normalize(' 25565-25700 ,7777-7777'))->toBe('25565-25700,7777')
        ->and(GatewayPorts::contains([[25565, 25700]], 25700))->toBeTrue()
        ->and(GatewayPorts::contains([[25565, 25700]], 25701))->toBeFalse();
});

test('hosts are host names or public IPv4 addresses', function (): void {
    expect(GatewayHost::error('play.monoforge.net'))->toBeNull()
        ->and(GatewayHost::error('PLAY.monoforge.net.'))->toBeNull()
        ->and(GatewayHost::error('8.8.8.8'))->toBeNull()
        ->and(GatewayHost::error('10.0.0.1'))->not->toBeNull()
        ->and(GatewayHost::error('play_server.example.com'))->not->toBeNull()
        ->and(GatewayHost::error('-bad.example.com'))->not->toBeNull()
        ->and(GatewayHost::error('example.123'))->not->toBeNull()
        ->and(GatewayHost::normalize(' Play.Example.com. '))->toBe('play.example.com');
});

test('the address leaves out 25565 only for Minecraft Java', function (): void {
    expect(ServerAddress::join('play.example.com', 25565, true))->toBe('play.example.com')
        ->and(ServerAddress::join('play.example.com', 25565, false))->toBe('play.example.com:25565')
        ->and(ServerAddress::join('play.example.com', 25570, true))->toBe('play.example.com:25570');
});

test('ports are summarized as runs', function (): void {
    expect(GameGatewayService::summarize([25567, 25565, 25566, 25566, 30000, 24454]))->toBe('24454, 25565-25567, 30000')
        ->and(GameGatewayService::summarize([]))->toBe('');
});
