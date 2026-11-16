<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Pterodactyl\Models\Game;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    protected $model = Game::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'slug' => 'valheim-'.Str::lower(Str::random(6)),
            'name' => 'Valheim',
            'color' => '#2E7D9A',
            'icon' => 'swords',
            'auto_tunnels' => false,
            'extra_ports' => [],
        ];
    }
}
