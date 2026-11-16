<?php

declare(strict_types=1);

namespace Pterodactyl\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Once;
use Pterodactyl\Services\Games\GameDetector;

/**
 * A game in Admin > Games: a name, a colour and icon, and the eggs that run it. The game a
 * server's egg belongs to decides which game-specific pages and helpers the server gets.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $color
 * @property string|null $icon
 * @property bool $built_in
 * @property bool $auto_tunnels
 * @property list<array{key: string, name: string, protocol: string, port: int|null, allocation: int|null}>|null $extra_ports
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Egg> $eggs
 */
#[Fillable([
    'slug',
    'name',
    'color',
    'icon',
    'auto_tunnels',
    'extra_ports',
])]
#[RouteKey('id')]
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    public const string RESOURCE_NAME = 'game';

    /** What each built-in game adds to its servers' pages. */
    private const array FEATURES = [
        GameDetector::MINECRAFT => ['plugins', 'versions', 'player_count'],
        GameDetector::TERRARIA => ['world_settings', 'player_count'],
    ];

    protected $attributes = [
        'built_in' => false,
        'auto_tunnels' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'built_in' => 'boolean',
            'auto_tunnels' => 'boolean',
            'extra_ports' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<Egg, $this>
     */
    public function eggs(): HasMany
    {
        return $this->hasMany(Egg::class, 'game_id');
    }

    /**
     * The game-specific features its servers get; a custom game has none yet.
     *
     * @return list<string>
     */
    public function features(): array
    {
        return $this->built_in ? (self::FEATURES[$this->slug] ?? []) : [];
    }

    /**
     * The extra ports, each with every key present.
     *
     * @return list<array{key: string, name: string, protocol: string, port: int|null, allocation: int|null}>
     */
    public function extraPorts(): array
    {
        $ports = [];
        foreach ($this->extra_ports ?? [] as $port) {
            $ports[] = [
                'key' => (string) $port['key'],
                'name' => (string) $port['name'],
                'protocol' => in_array($port['protocol'], ['tcp', 'udp', 'both'], true) ? $port['protocol'] : 'both',
                'port' => isset($port['port']) ? (int) $port['port'] : null,
                'allocation' => isset($port['allocation']) ? (int) $port['allocation'] : null,
            ];
        }

        return $ports;
    }

    protected static function booted(): void
    {
        // GameResolver remembers the built-in games for the request; a change must show at once.
        static::saved(fn (): mixed => Once::flush());
        static::deleted(fn (): mixed => Once::flush());
    }
}
