<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Games\GameMappingTest;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Game;
use Pterodactyl\Models\Tag;
use Pterodactyl\Services\Games\GameDetector;
use Pterodactyl\Services\Games\GameEggBackfiller;
use Pterodactyl\Services\Query\ServerQuery;
use Pterodactyl\Services\Schedules\SchedulePresets;
use Pterodactyl\Services\Tunnels\TunnelProfile;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

uses(IntegrationTestCase::class);
uses(DatabaseTransactions::class);

function builtInGame(string $slug): Game
{
    return Game::query()->where('slug', $slug)->sole();
}

test('the backfill files detected eggs under the built-in games and never moves a claimed egg', function (): void {
    $paper = Egg::factory()->create(['name' => 'Paper']);
    $tshock = Egg::factory()->create(['name' => 'TShock']);
    $bedrock = Egg::factory()->create(['name' => 'Bedrock Dedicated']);
    $tagged = Egg::factory()->create(['name' => 'Some server']);
    $tagged->tags()->attach(Tag::query()->firstOrCreate(['slug' => 'minecraft'], ['name' => 'Minecraft']));
    $claimed = Egg::factory()->create(['name' => 'Purpur']);
    $custom = Game::factory()->create();
    $claimed->forceFill(['game_id' => $custom->id])->save();

    $filed = (new GameEggBackfiller(DB::connection()))->backfill();

    expect($filed)->toBeGreaterThanOrEqual(3)
        ->and($paper->refresh()->game_id)->toBe(builtInGame(GameDetector::MINECRAFT)->id)
        ->and($tagged->refresh()->game_id)->toBe(builtInGame(GameDetector::MINECRAFT)->id)
        ->and($tshock->refresh()->game_id)->toBe(builtInGame(GameDetector::TERRARIA)->id)
        ->and($bedrock->refresh()->game_id)->toBeNull()
        ->and($claimed->refresh()->game_id)->toBe($custom->id);

    // Running it again changes nothing.
    expect((new GameEggBackfiller(DB::connection()))->backfill())->toBe(0);
});

test('the detection matches what the tunnel profile always matched, Bedrock excluded', function (string $name, array $tags, ?string $game): void {
    expect(GameDetector::detect($name, $tags))->toBe($game);
})->with([
    ['Paper', [], GameDetector::MINECRAFT],
    ['Velocity proxy', [], GameDetector::MINECRAFT],
    ['Forge Enhanced', [], GameDetector::MINECRAFT],
    ['Custom', ['minecraft'], GameDetector::MINECRAFT],
    ['Minecraft: Bedrock', ['minecraft', 'bedrock'], null],
    ['PocketMine-MP', [], null],
    ['tModLoader', [], GameDetector::TERRARIA],
    ['Terraria Vanilla', [], GameDetector::TERRARIA],
    ['Discord bot', [], null],
    ['Rust', ['rust'], null],
]);

test('an egg claimed by Minecraft is Minecraft everywhere, whatever it is called', function (): void {
    $egg = Egg::factory()->create(['name' => 'Discord bot']);
    $server = $this->createServerModel(['egg_id' => $egg->id]);

    expect(TunnelProfile::forEgg($egg)['tunnel_type'])->toBeNull()
        ->and(SchedulePresets::isMinecraft($server))->toBeFalse()
        ->and(ServerQuery::supports($server))->toBeFalse();

    $egg->forceFill(['game_id' => builtInGame(GameDetector::MINECRAFT)->id])->save();
    $server = $server->fresh(['egg']);

    expect(TunnelProfile::forEgg($server->egg)['tunnel_type'])->toBe('minecraft-java')
        ->and(SchedulePresets::isMinecraft($server))->toBeTrue()
        ->and(ServerQuery::supports($server))->toBeTrue();
});

test('an egg the admin moved from Minecraft to Terraria stops being Minecraft', function (): void {
    $egg = Egg::factory()->create(['name' => 'Paper']);
    $server = $this->createServerModel(['egg_id' => $egg->id]);
    expect(SchedulePresets::isMinecraft($server))->toBeTrue();

    $egg->forceFill(['game_id' => builtInGame(GameDetector::TERRARIA)->id])->save();
    $server = $server->fresh(['egg']);

    expect(SchedulePresets::isMinecraft($server))->toBeFalse()
        ->and(TunnelProfile::forEgg($server->egg)['tunnel_type'])->toBe('terraria');
});
