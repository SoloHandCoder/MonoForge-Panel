<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Settings\StatusPageSettingsTest;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Status\PublicStatusService;
use Pterodactyl\Services\Status\StatusPageSettings;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

test('every node is listed with its default display name, only nodes in the shop shown until chosen', function (): void {
    $eu = Location::factory()->create(['short' => 'EU']);
    $us = Location::factory()->create(['short' => 'US']);
    $first = Node::factory()->create(['location_id' => $eu->id, 'name' => 'fsn-01', 'shop_enabled' => true]);
    $other = Node::factory()->create(['location_id' => $us->id, 'name' => 'nyc-01', 'shop_enabled' => true]);
    $second = Node::factory()->create(['location_id' => $eu->id, 'name' => 'fsn-02', 'public' => false, 'shop_enabled' => true]);
    $offShop = Node::factory()->create(['location_id' => $us->id, 'name' => 'nyc-02', 'shop_enabled' => false]);

    $nodes = collect($this->getJson(route('api.admin.settings.status'))->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('public_url', '/status')
        ->json('nodes'))->keyBy('id');

    expect($nodes[$first->id])->toMatchArray(['name' => 'fsn-01', 'location' => 'EU', 'visible' => true, 'display_name' => 'EU Node 1', 'custom_name' => '', 'default_name' => 'EU Node 1'])
        ->and($nodes[$second->id])->toMatchArray(['visible' => false, 'display_name' => 'EU Node 2'])
        ->and($nodes[$other->id])->toMatchArray(['visible' => true, 'display_name' => 'US Node 1'])
        ->and($nodes[$offShop->id])->toMatchArray(['visible' => false, 'display_name' => 'US Node 2']);
});

test('an explicit choice to show a node outside the shop is kept', function (): void {
    $node = Node::factory()->create(['location_id' => Location::factory()->create(['short' => 'EU'])->id, 'shop_enabled' => false]);
    expect(StatusPageSettings::visibleNodes())->toBe([]);

    $this->putJson(route('api.admin.settings.status.update'), ['enabled' => true, 'nodes' => [['id' => $node->id, 'visible' => true, 'display_name' => 'Lab']]])->assertOk();

    expect(array_map(fn (array $row): array => [$row['node']->id, $row['name']], StatusPageSettings::visibleNodes()))->toBe([[$node->id, 'Lab']]);
});

test('updating turns the page off, hides nodes, renames them and clears the public cache', function (): void {
    $location = Location::factory()->create(['short' => 'EU']);
    $shown = Node::factory()->create(['location_id' => $location->id]);
    $hidden = Node::factory()->create(['location_id' => $location->id]);
    Cache::put(PublicStatusService::CACHE_KEY, ['stale' => true], 60);

    $this->putJson(route('api.admin.settings.status.update'), [
        'enabled' => false,
        'nodes' => [
            ['id' => $shown->id, 'visible' => true, 'display_name' => '  Frankfurt  '],
            ['id' => $hidden->id, 'visible' => false, 'display_name' => null],
        ],
    ])->assertOk()->assertJsonPath('enabled', false);

    expect(StatusPageSettings::enabled())->toBeFalse()
        ->and(Cache::has(PublicStatusService::CACHE_KEY))->toBeFalse()
        ->and(array_map(fn (array $row): array => [$row['node']->id, $row['name']], StatusPageSettings::visibleNodes()))->toBe([[$shown->id, 'Frankfurt']]);

    // A node left out keeps its choice; a blank name goes back to the default.
    $this->putJson(route('api.admin.settings.status.update'), [
        'enabled' => true,
        'nodes' => [['id' => $shown->id, 'visible' => true, 'display_name' => '']],
    ])->assertOk();

    expect(array_map(fn (array $row): array => [$row['node']->id, $row['name']], StatusPageSettings::visibleNodes()))->toBe([[$shown->id, 'EU Node 1']]);
});

test('names are limited and nodes must exist', function (): void {
    $node = Node::factory()->for(Location::factory())->create();

    $this->putJson(route('api.admin.settings.status.update'), [
        'enabled' => true,
        'nodes' => [['id' => $node->id, 'visible' => true, 'display_name' => str_repeat('a', StatusPageSettings::NAME_MAX_LENGTH + 1)]],
    ])->assertUnprocessable();

    $this->putJson(route('api.admin.settings.status.update'), [
        'enabled' => true,
        'nodes' => [['id' => $node->id + 1000, 'visible' => true]],
    ])->assertUnprocessable();
});

test('non admins cannot read or change the status page settings', function (string $method, string $route): void {
    $this->actingAsNonAdmin();

    $this->assertAccessDeniedJson($this->{$method}(route($route)));
})->with([['getJson', 'api.admin.settings.status'], ['putJson', 'api.admin.settings.status.update']]);
