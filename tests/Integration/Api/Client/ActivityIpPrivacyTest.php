<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\ActivityIpPrivacyTest;

use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\User;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

// Loopback and private addresses are never shown, so entries come from a public address.
beforeEach(function (): void {
    request()->server->set('REMOTE_ADDR', '203.0.113.50');
});

test('a normal user actor keeps their address visible to themselves', function (): void {
    $user = User::factory()->create(['root_admin' => false]);
    Activity::event('user:account.email-changed')->actor($user)->subject($user)->withRequestMetadata()->log();

    $row = $this->actingAs($user)->getJson('/api/client/account/activity')->assertOk()->json('data.0.attributes');

    expect($row['ip'])->toBe('203.0.113.50');
});

test('a root admin actor address is hidden from the account activity of the user it changed', function (): void {
    $user = User::factory()->create(['root_admin' => false]);
    $admin = User::factory()->create(['root_admin' => true]);
    Activity::event('user:account.email-changed')->actor($admin)->subject($user)->withRequestMetadata()->log();

    $row = $this->actingAs($user)->getJson('/api/client/account/activity')->assertOk()->json('data.0.attributes');

    expect($row['ip'])->toBeNull();
});

test('a root admin viewer still sees the address of normal users', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);
    $user = User::factory()->create(['root_admin' => false]);
    Activity::event('user:account.email-changed')->actor($user)->subject($admin)->withRequestMetadata()->log();

    $row = $this->actingAs($admin)->getJson('/api/client/account/activity')->assertOk()->json('data.0.attributes');

    expect($row['ip'])->toBe('203.0.113.50');
});

test('node address properties are scrubbed for non admins', function (): void {
    $user = User::factory()->create(['root_admin' => false]);
    Activity::event('user:account.email-changed')->actor($user)->subject($user)
        ->property(['fqdn' => 'node.example.test', 'node_ip' => '10.0.0.1', 'name' => 'kept'])->log();

    $properties = $this->actingAs($user)->getJson('/api/client/account/activity')->assertOk()->json('data.0.attributes.properties');

    expect($properties)->not->toHaveKeys(['fqdn', 'node_ip'])->and($properties['name'])->toBe('kept');
});

test('a normal user entry from a loopback or private address is hidden, even from themselves', function (string $ip): void {
    request()->server->set('REMOTE_ADDR', $ip);
    $user = User::factory()->create(['root_admin' => false]);
    Activity::event('user:account.email-changed')->actor($user)->subject($user)->withRequestMetadata()->log();

    $row = $this->actingAs($user)->getJson('/api/client/account/activity')->assertOk()->json('data.0.attributes');

    expect($row['ip'])->toBeNull()->and($row['properties']['ip'])->toBe('[hidden]');
})->with(['127.0.0.1', '::1', '192.168.1.5', 'fdba:1:2::3']);

test('an address on the admin hidden list is hidden from client and admin activity', function (): void {
    config(['monoforge.privacy.hidden_ips' => "2a02:1234::/32\n198.51.100.0/24"]);
    request()->server->set('REMOTE_ADDR', '2a02:1234:5::9');
    $user = User::factory()->create(['root_admin' => false]);
    $admin = User::factory()->create(['root_admin' => true]);
    Activity::event('user:account.email-changed')->actor($user)->subject($user)->withRequestMetadata()->log();
    Activity::event('admin:server.suspend')->actor($user)->subject($user)->withRequestMetadata()->log();

    $own = $this->actingAs($user)->getJson('/api/client/account/activity')->assertOk()->json('data.0.attributes');
    $adminRow = $this->actingAs($admin)->getJson('/api/admin/activity')->assertOk()->json('data.0.attributes');

    expect($own['ip'])->toBeNull()->and($adminRow['ip'])->toBeNull()->and($adminRow['properties']['ip'])->toBe('[hidden]');
});

test('an ip property that is an infrastructure address is hidden even when the entry address is shown', function (): void {
    $user = User::factory()->create(['root_admin' => false]);
    Activity::event('user:account.email-changed')->actor($user)->subject($user)->withRequestMetadata()->property('ip', '10.0.0.4')->log();

    $row = $this->actingAs($user)->getJson('/api/client/account/activity')->assertOk()->json('data.0.attributes');

    expect($row['ip'])->toBe('203.0.113.50')->and($row['properties']['ip'])->toBe('[hidden]');
});

test('a system entry is hidden in admin activity even from a public address', function (): void {
    $admin = User::factory()->create(['root_admin' => true]);
    Activity::event('admin:server.build')->anonymous()->withRequestMetadata()->log();

    $row = $this->actingAs($admin)->getJson('/api/admin/activity')->assertOk()->json('data.0.attributes');

    expect($row['ip'])->toBeNull();
});
