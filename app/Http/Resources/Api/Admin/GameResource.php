<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Admin;

use Illuminate\Http\Request;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Game;
use Pterodactyl\Models\Tag;
use Pterodactyl\Services\Games\GameDetector;

/**
 * @extends AdminResource<Game>
 */
#[ResponseField('slug', description: 'The stable identifier the panel matches on. Built-in games are `minecraft` and `terraria`.', example: 'minecraft')]
#[ResponseField('color', description: 'Hex colour of the game chip.', example: '#5B8731')]
#[ResponseField('icon', description: 'A lucide icon name.', example: 'pickaxe', nullable: true)]
#[ResponseField('built_in', 'boolean', 'Built-in games ship with the panel and cannot be deleted.', example: true)]
#[ResponseField('auto_tunnels', 'boolean', "Whether servers on this game's eggs get playit.gg tunnels automatically.", example: true)]
#[ResponseField('features', description: 'What the game adds to its servers: plugins, versions, player_count, world_settings.', schema: ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['plugins', 'versions']])]
#[ResponseField('egg_ids', description: 'The eggs the game claims. An egg belongs to at most one game.', schema: ['type' => 'array', 'items' => ['type' => 'integer'], 'example' => [1, 2]])]
#[ResponseField('detected_egg_ids', description: 'Eggs no game claims that the built-in detection treats as this game. Always empty for custom games.', schema: ['type' => 'array', 'items' => ['type' => 'integer'], 'example' => [3]])]
#[ResponseField('extra_ports', description: 'Tunnels besides the main game port: each matches an extra allocation of the server by port, or by its position among the extra allocations.', schema: [
    'type' => 'array',
    'items' => [
        'type' => 'object',
        'required' => ['key', 'name', 'protocol', 'port', 'allocation'],
        'properties' => [
            'key' => ['type' => 'string', 'example' => 'voice'],
            'name' => ['type' => 'string', 'example' => 'Voice chat'],
            'protocol' => ['type' => 'string', 'enum' => ['tcp', 'udp', 'both'], 'example' => 'udp'],
            'port' => ['type' => 'integer', 'nullable' => true, 'description' => 'An extra allocation with this port.', 'example' => 24454],
            'allocation' => ['type' => 'integer', 'nullable' => true, 'description' => 'Otherwise the Nth extra allocation (1 is the first after the primary).', 'example' => null],
        ],
    ],
])]
class GameResource extends AdminResource
{
    public function resourceName(): string
    {
        return Game::RESOURCE_NAME;
    }

    public function toArray(Request $request): array
    {
        $game = $this->resource;

        return [
            'id' => $game->id,
            'slug' => $game->slug,
            'name' => $game->name,
            'color' => $game->color,
            'icon' => $game->icon,
            'built_in' => $game->built_in,
            'auto_tunnels' => $game->auto_tunnels,
            'features' => $game->features(),
            'egg_ids' => $game->exists ? Egg::query()->where('game_id', $game->id)->orderBy('id')->get(['id'])->map(fn (Egg $egg): int => $egg->id)->values()->all() : [],
            'detected_egg_ids' => $this->detectedEggIds($game),
            'extra_ports' => $game->extraPorts(),
            'created_at' => $this->formatTimestamp($game->created_at),
            'updated_at' => $this->formatTimestamp($game->updated_at),
        ];
    }

    /** @return list<int> */
    private function detectedEggIds(Game $game): array
    {
        if (! $game->built_in || ! $game->exists) {
            return [];
        }

        return array_values(Egg::query()->whereNull('game_id')->with('tags')->orderBy('id')->get()
            ->filter(fn (Egg $egg): bool => GameDetector::detect($egg->name, array_values($egg->tags->map(fn (Tag $tag): string => $tag->slug)->all())) === $game->slug)
            ->map(fn (Egg $egg): int => $egg->id)
            ->all());
    }
}
