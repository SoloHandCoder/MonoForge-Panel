<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Api\Admin\Games\GameControllerTest;

use Illuminate\Http\Response;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Game;
use Pterodactyl\Models\Tag;
use Pterodactyl\Services\Games\GameDetector;
use Pterodactyl\Services\Games\GameResolver;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;

uses(AdminApiIntegrationTestCase::class);

function builtIn(string $slug): Game
{
    return Game::query()->where('slug', $slug)->sole();
}

test('the built-in games ship with the panel', function (): void {
    $response = $this->getJson(route('api.admin.games'))->assertOk();

    $slugs = collect($response->json('data'))->pluck('attributes.slug')->all();
    expect($slugs)->toContain(GameDetector::MINECRAFT, GameDetector::TERRARIA);

    $minecraft = collect($response->json('data'))->firstWhere('attributes.slug', GameDetector::MINECRAFT);
    expect($minecraft['attributes']['built_in'])->toBeTrue()
        ->and($minecraft['attributes']['auto_tunnels'])->toBeTrue()
        ->and($minecraft['attributes']['features'])->toContain('plugins', 'versions')
        ->and($minecraft['attributes']['extra_ports'][0])->toMatchArray(['key' => 'voice', 'protocol' => 'udp', 'port' => 24454]);
});

test('a detected egg no game claims is listed as detected', function (): void {
    $egg = Egg::factory()->create(['name' => 'Paper']);

    $response = $this->getJson(route('api.admin.games.view', ['game' => builtIn(GameDetector::MINECRAFT)->id]))->assertOk();

    expect($response->json('attributes.detected_egg_ids'))->toContain($egg->id)
        ->and($response->json('attributes.egg_ids'))->not->toContain($egg->id);
});

test('a custom game can be created, edited and deleted', function (): void {
    $egg = Egg::factory()->create(['name' => 'Valheim Plus']);

    $response = $this->postJson(route('api.admin.games.store'), ['name' => 'Valheim', 'color' => '#2e7d9a', 'icon' => 'swords', 'egg_ids' => [$egg->id]])
        ->assertCreated()
        ->assertJsonPath('object', 'game')
        ->assertJsonPath('attributes.slug', 'valheim')
        ->assertJsonPath('attributes.color', '#2E7D9A')
        ->assertJsonPath('attributes.built_in', false)
        ->assertJsonPath('attributes.features', [])
        ->assertJsonPath('attributes.egg_ids', [$egg->id]);
    $id = $response->json('attributes.id');

    expect(GameResolver::forEgg($egg->refresh())?->slug)->toBe('valheim');
    expect(ActivityLog::query()->where('event', 'admin:game.create')->sole()->properties['name'])->toBe('Valheim');

    $this->putJson(route('api.admin.games.update', ['game' => $id]), [
        'name' => 'Valheim Dedicated',
        'auto_tunnels' => true,
        'extra_ports' => [['name' => 'Query', 'protocol' => 'udp', 'port' => 2457]],
        'egg_ids' => [],
    ])->assertOk()
        ->assertJsonPath('attributes.name', 'Valheim Dedicated')
        ->assertJsonPath('attributes.slug', 'valheim')
        ->assertJsonPath('attributes.auto_tunnels', true)
        ->assertJsonPath('attributes.extra_ports.0', ['key' => 'query', 'name' => 'Query', 'protocol' => 'udp', 'port' => 2457, 'allocation' => null])
        ->assertJsonPath('attributes.egg_ids', []);
    expect($egg->refresh()->game_id)->toBeNull();

    $this->deleteJson(route('api.admin.games.delete', ['game' => $id]))->assertNoContent();
    $this->assertDatabaseMissing('games', ['id' => $id]);
});

test('an egg belongs to at most one game: claiming it moves it', function (): void {
    $egg = Egg::factory()->create(['name' => 'Custom Java server']);
    $minecraft = builtIn(GameDetector::MINECRAFT);
    $this->putJson(route('api.admin.games.update', ['game' => $minecraft->id]), ['egg_ids' => [$egg->id]])->assertOk();
    expect($egg->refresh()->game_id)->toBe($minecraft->id);

    $terraria = builtIn(GameDetector::TERRARIA);
    $this->putJson(route('api.admin.games.update', ['game' => $terraria->id]), ['egg_ids' => [$egg->id]])->assertOk();

    expect($egg->refresh()->game_id)->toBe($terraria->id)
        ->and(GameResolver::forEgg($egg)?->slug)->toBe(GameDetector::TERRARIA);
    $this->getJson(route('api.admin.games.view', ['game' => $minecraft->id]))->assertJsonPath('attributes.egg_ids', []);
});

test('the claimed game wins over detection, and unclaimed eggs fall back to it', function (): void {
    $paper = Egg::factory()->create(['name' => 'Paper']);
    $bedrock = Egg::factory()->create(['name' => 'Bedrock Dedicated']);
    $bedrock->tags()->attach(Tag::query()->firstOrCreate(['slug' => 'minecraft'], ['name' => 'Minecraft']));
    $other = Egg::factory()->create(['name' => 'Discord bot']);

    expect(GameResolver::forEgg($paper)?->slug)->toBe(GameDetector::MINECRAFT)
        ->and(GameResolver::forEgg($bedrock->load('tags'))?->slug)->toBeNull()
        ->and(GameResolver::forEgg($other))->toBeNull();

    $paper->forceFill(['game_id' => builtIn(GameDetector::TERRARIA)->id])->save();
    expect(GameResolver::forEgg($paper->refresh())?->slug)->toBe(GameDetector::TERRARIA);
});

test('built-in games cannot be deleted', function (): void {
    $this->deleteJson(route('api.admin.games.delete', ['game' => builtIn(GameDetector::MINECRAFT)->id]))
        ->assertStatus(Response::HTTP_BAD_REQUEST)
        ->assertJsonPath('errors.0.detail', 'Built-in games cannot be deleted.');
});

test('the input is validated', function (array $body, string $field): void {
    $this->postJson(route('api.admin.games.store'), $body)
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', $field);
})->with([
    'no name' => [['color' => '#123456'], 'name'],
    'bad colour' => [['name' => 'X', 'color' => 'red'], 'color'],
    'bad icon' => [['name' => 'X', 'icon' => '<svg>'], 'icon'],
    'unknown egg' => [['name' => 'X', 'egg_ids' => [999999]], 'egg_ids.0'],
    'bad protocol' => [['name' => 'X', 'extra_ports' => [['name' => 'Voice', 'protocol' => 'icmp', 'port' => 1]]], 'extra_ports.0.protocol'],
    'port without target' => [['name' => 'X', 'extra_ports' => [['name' => 'Voice', 'protocol' => 'udp']]], 'extra_ports.0.port'],
]);

test('non-admins cannot reach the games endpoints', function (): void {
    $this->actingAsNonAdmin();

    $this->getJson(route('api.admin.games'))->assertForbidden();
    $this->postJson(route('api.admin.games.store'), ['name' => 'X'])->assertForbidden();
});
