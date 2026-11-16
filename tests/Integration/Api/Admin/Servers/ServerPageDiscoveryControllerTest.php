<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Servers\ServerPageDiscoveryControllerTest;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Services\ServerPages\PublicServerPages;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

function adminPage(Server $server, array $attributes = []): ServerPage
{
    return ServerPage::query()->forceCreate([
        'server_id' => $server->id,
        'slug' => 'skyblock',
        'published' => true,
        ...$attributes,
    ]);
}

test('an administrator sees whether the page is listed', function (): void {
    $server = $this->createServerModel(['status' => null]);

    $this->getJson(route('api.admin.servers.page', ['server' => $server->id]))
        ->assertOk()
        ->assertJsonPath('exists', false)
        ->assertJsonPath('listed', false);

    adminPage($server);

    $this->getJson(route('api.admin.servers.page', ['server' => $server->id]))
        ->assertOk()
        ->assertExactJson([
            'exists' => true,
            'slug' => 'skyblock',
            'url' => mb_rtrim(config()->string('app.url'), '/').'/s/skyblock',
            'published' => true,
            'discoverable' => true,
            'hidden_from_discovery' => false,
            'listed' => true,
        ]);
});

test('an administrator hides a page from discovery without unpublishing it, and lists it again', function (): void {
    $server = $this->createServerModel(['status' => null, 'name' => 'Skyblock Island']);
    $page = adminPage($server);

    $this->putJson(route('api.admin.servers.page.discovery', ['server' => $server->id]), ['hidden' => true])
        ->assertOk()
        ->assertJsonPath('hidden_from_discovery', true)
        ->assertJsonPath('listed', false)
        ->assertJsonPath('published', true);

    expect($page->refresh()->hidden_from_discovery)->toBeTrue()
        ->and($page->published)->toBeTrue()
        ->and(ActivityLog::query()->where('event', 'admin:server.page-discovery-hide')->exists())->toBeTrue();

    Cache::flush();
    $this->getJson('/api/public/servers')->assertOk()->assertJsonCount(0, 'data');
    // The page itself is still published and found at its address.
    expect(resolve(PublicServerPages::class)->find('skyblock')?->id)->toBe($page->id);

    $this->putJson(route('api.admin.servers.page.discovery', ['server' => $server->id]), ['hidden' => false])
        ->assertOk()
        ->assertJsonPath('listed', true);
    expect(ActivityLog::query()->where('event', 'admin:server.page-discovery-show')->exists())->toBeTrue();

    Cache::flush();
    $this->getJson('/api/public/servers')->assertOk()->assertJsonCount(1, 'data');
});

test('hiding needs a page and a boolean', function (): void {
    $server = $this->createServerModel(['status' => null]);

    $this->putJson(route('api.admin.servers.page.discovery', ['server' => $server->id]), ['hidden' => true])->assertNotFound();

    adminPage($server);
    $this->putJson(route('api.admin.servers.page.discovery', ['server' => $server->id]), ['hidden' => 'perhaps'])
        ->assertUnprocessable();
});

test('a user who is not an administrator cannot see or hide pages', function (): void {
    $server = $this->createServerModel(['status' => null]);
    adminPage($server);
    $this->actingAsNonAdmin();

    $this->assertAccessDeniedJson($this->getJson(route('api.admin.servers.page', ['server' => $server->id])));
    $this->assertAccessDeniedJson($this->putJson(route('api.admin.servers.page.discovery', ['server' => $server->id]), ['hidden' => true]));
});
