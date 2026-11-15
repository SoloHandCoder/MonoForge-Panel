<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Activity\InfrastructureIpsTest;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Activity\InfrastructureIps;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use RuntimeException;

uses(IntegrationTestCase::class, DatabaseTransactions::class);

/**
 * @param  array<string, list<string>>  $records
 * @param  list<string>  $lookups  the host names that were looked up
 */
function fakeDns(array $records, array &$lookups = []): InfrastructureIps
{
    $service = new InfrastructureIps(resolve(CacheRepository::class), function (string $host) use ($records, &$lookups): array {
        $lookups[] = $host;

        return $records[$host] ?? throw new RuntimeException('NXDOMAIN');
    });
    app()->instance(InfrastructureIps::class, $service);

    return $service;
}

beforeEach(function (): void {
    resolve(CacheRepository::class)->clear();
    config(['monoforge.privacy.hidden_ips' => '']);
});

test('loopback, private, unique local and link-local addresses are infrastructure', function (string $ip): void {
    expect(fakeDns([])->contains($ip))->toBeTrue();
})->with(['127.0.0.1', '127.1.2.3', '::1', '10.4.5.6', '172.16.0.1', '172.31.255.254', '192.168.1.10', '169.254.1.1', '100.64.0.1', 'fdba:1234::1', 'fe80::1', '::ffff:127.0.0.1', '::ffff:192.168.0.1']);

test('public addresses are not infrastructure', function (string $ip): void {
    expect(fakeDns([])->contains($ip))->toBeFalse();
})->with(['203.0.113.7', '8.8.8.8', '172.32.0.1', '2a02:1234::1', '2001:db8::1', '::ffff:203.0.113.7']);

test('invalid or missing addresses are not infrastructure', function (?string $ip): void {
    expect(fakeDns([])->contains($ip))->toBeFalse();
})->with([null, '', 'not-an-ip', '999.1.1.1']);

test('the addresses node FQDNs resolve to are infrastructure', function (): void {
    Node::factory()->for(Location::factory())->create(['fqdn' => 'node1.example.test']);
    Node::factory()->for(Location::factory())->create(['fqdn' => 'gone.example.test']);
    $service = fakeDns(['node1.example.test' => ['198.51.100.20', '2A02:1234:0:0::5']]);

    expect($service->contains('198.51.100.20'))->toBeTrue()
        ->and($service->contains('2a02:1234::5'))->toBeTrue()
        ->and($service->contains('2a02:1234::6'))->toBeFalse()
        ->and($service->contains('198.51.100.21'))->toBeFalse();
});

test('a node FQDN that is an IP address is used as is', function (): void {
    Node::factory()->for(Location::factory())->create(['fqdn' => '198.51.100.30']);
    Node::factory()->for(Location::factory())->create(['fqdn' => '[2a02:abcd::1]']);

    $lookups = [];
    $service = fakeDns([], $lookups);

    expect($service->contains('198.51.100.30'))->toBeTrue()
        ->and($service->contains('2a02:abcd::1'))->toBeTrue()
        ->and($lookups)->not->toContain('198.51.100.30')->not->toContain('[2a02:abcd::1]')->not->toContain('2a02:abcd::1');
});

test('resolved node addresses are cached', function (): void {
    Node::factory()->for(Location::factory())->create(['fqdn' => 'node1.example.test']);

    $lookups = [];
    fakeDns(['node1.example.test' => ['198.51.100.20']], $lookups)->contains('198.51.100.20');
    // A fresh instance (the next request) reads the cache instead of DNS.
    expect(fakeDns(['node1.example.test' => ['198.51.100.20']], $lookups)->contains('198.51.100.20'))->toBeTrue()
        ->and(array_count_values($lookups)['node1.example.test'])->toBe(1);
});

test('a changed node FQDN is resolved again', function (): void {
    $node = Node::factory()->for(Location::factory())->create(['fqdn' => 'node1.example.test']);
    $records = ['node1.example.test' => ['198.51.100.20'], 'node2.example.test' => ['198.51.100.40']];
    fakeDns($records)->contains('198.51.100.20');

    $node->update(['fqdn' => 'node2.example.test']);

    expect(fakeDns($records)->contains('198.51.100.40'))->toBeTrue();
});

test('the admin hidden list accepts IPs and CIDR ranges for IPv4 and IPv6', function (): void {
    config(['monoforge.privacy.hidden_ips' => "203.0.113.7\n\n# the office\n198.51.100.0/24\r\n2a02:1234:5678::/48\n2001:db8::42"]);
    $service = fakeDns([]);

    expect($service->contains('203.0.113.7'))->toBeTrue()
        ->and($service->contains('203.0.113.8'))->toBeFalse()
        ->and($service->contains('198.51.100.99'))->toBeTrue()
        ->and($service->contains('198.51.101.1'))->toBeFalse()
        ->and($service->contains('2a02:1234:5678:ffff::1'))->toBeTrue()
        ->and($service->contains('2a02:1234:5679::1'))->toBeFalse()
        ->and($service->contains('2001:DB8:0::42'))->toBeTrue();
});

test('hidden list entries are validated', function (string $entry, bool $valid): void {
    expect(InfrastructureIps::isValidEntry($entry))->toBe($valid);
})->with([
    ['203.0.113.7', true],
    ['198.51.100.0/24', true],
    ['2a02:1234::/32', true],
    ['2a02:1234::/128', true],
    ['198.51.100.0/33', false],
    ['2a02::/129', false],
    ['198.51.100.0/', false],
    ['example.com', false],
    ['1.2.3', false],
]);

test('activity entries hide infrastructure addresses even from failed logins on the own account', function (): void {
    Node::factory()->for(Location::factory())->create(['fqdn' => 'node1.example.test']);
    fakeDns(['node1.example.test' => ['2a02:1234::5']]);
    config(['monoforge.privacy.hidden_ips' => '203.0.113.0/24']);
    $user = User::factory()->create(['root_admin' => false]);

    $log = fn (string $ip): ActivityLog => Activity::event('auth:fail')->anonymous()->subject($user)->property('ip', $ip)->log()->forceFill(['ip' => $ip]);

    expect($log('2a02:1234::5')->visibleIp())->toBeNull()
        ->and($log('::1')->visibleIp())->toBeNull()
        ->and($log('fdba::7')->visibleIp())->toBeNull()
        ->and($log('203.0.113.9')->visibleIp())->toBeNull()
        // A system entry (no user actor) hides even an external address.
        ->and($log('198.51.100.9')->visibleIp())->toBeNull();
});
