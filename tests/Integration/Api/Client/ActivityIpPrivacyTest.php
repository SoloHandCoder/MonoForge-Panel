<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Client\ActivityIpPrivacyTest;

use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\User;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

uses(ClientApiIntegrationTestCase::class);

test('a normal user actor keeps their address visible to themselves', function (): void {
    $user = User::factory()->create(['root_admin' => false]);
    Activity::event('user:account.email-changed')->actor($user)->subject($user)->withRequestMetadata()->log();

    $row = $this->actingAs($user)->getJson('/api/client/account/activity')->assertOk()->json('data.0.attributes');

    expect($row['ip'])->toBe('127.0.0.1');
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

    expect($row['ip'])->toBe('127.0.0.1');
});

test('node address properties are scrubbed for non admins', function (): void {
    $user = User::factory()->create(['root_admin' => false]);
    Activity::event('user:account.email-changed')->actor($user)->subject($user)
        ->property(['fqdn' => 'node.example.test', 'node_ip' => '10.0.0.1', 'name' => 'kept'])->log();

    $properties = $this->actingAs($user)->getJson('/api/client/account/activity')->assertOk()->json('data.0.attributes.properties');

    expect($properties)->not->toHaveKeys(['fqdn', 'node_ip'])->and($properties['name'])->toBe('kept');
});
