<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Games;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Game;

/**
 * Creates, edits and deletes the games of Admin > Games. Saving a game's eggs moves each egg
 * to it from any other game (an egg belongs to at most one) and releases the eggs it no
 * longer lists, which then fall back to the built-in detection.
 */
final readonly class GameService
{
    public const int MAX_EXTRA_PORTS = 5;

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'between:1,64'],
            'color' => ['sometimes', 'string', 'hex_color', 'size:7'],
            'icon' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,47}$/'],
            'auto_tunnels' => ['sometimes', 'boolean'],
            'egg_ids' => ['sometimes', 'array', 'max:500'],
            'egg_ids.*' => ['integer', 'distinct', Rule::exists('eggs', 'id')],
            'extra_ports' => ['sometimes', 'array', 'max:'.self::MAX_EXTRA_PORTS],
            'extra_ports.*.name' => ['required', 'string', 'between:1,32'],
            'extra_ports.*.protocol' => ['required', 'string', Rule::in(['tcp', 'udp', 'both'])],
            'extra_ports.*.port' => ['nullable', 'integer', 'between:1,65535', 'required_without:extra_ports.*.allocation'],
            'extra_ports.*.allocation' => ['nullable', 'integer', 'between:1,20', 'required_without:extra_ports.*.port'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input  validated against {@see rules()}
     */
    public function create(array $input): Game
    {
        $game = new Game;
        $game->slug = $this->uniqueSlug(is_string($input['name'] ?? null) ? $input['name'] : 'game');
        $game->name = 'game';
        $game->color = '#6B7280';

        return $this->save($game, $input);
    }

    /**
     * @param  array<string, mixed>  $input  validated against {@see rules()}
     */
    public function update(Game $game, array $input): Game
    {
        return $this->save($game, $input);
    }

    /**
     * @throws DisplayException
     */
    public function delete(Game $game): void
    {
        throw_if($game->built_in, DisplayException::class, 'Built-in games cannot be deleted.');

        DB::transaction(function () use ($game): void {
            Egg::query()->where('game_id', $game->id)->update(['game_id' => null]);
            $game->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function save(Game $game, array $input): Game
    {
        if (is_string($input['name'] ?? null)) {
            $game->name = mb_trim($input['name']);
        }

        if (is_string($input['color'] ?? null)) {
            $game->color = mb_strtoupper($input['color']);
        }

        if (array_key_exists('icon', $input)) {
            $game->icon = is_string($input['icon']) && $input['icon'] !== '' ? $input['icon'] : null;
        }

        if (array_key_exists('auto_tunnels', $input)) {
            $game->auto_tunnels = (bool) $input['auto_tunnels'];
        }

        if (is_array($input['extra_ports'] ?? null)) {
            $game->extra_ports = $this->extraPorts($input['extra_ports']);
        }

        DB::transaction(function () use ($game, $input): void {
            $game->save();

            if (is_array($input['egg_ids'] ?? null)) {
                $ids = array_values(array_filter(array_map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $input['egg_ids'])));
                Egg::query()->where('game_id', $game->id)->whereNotIn('id', $ids)->update(['game_id' => null]);
                if ($ids !== []) {
                    Egg::query()->whereIn('id', $ids)->update(['game_id' => $game->id]);
                }
            }
        });

        return $game->refresh();
    }

    /**
     * Normalised extra ports, each with a key unique within the game.
     *
     * @param  array<mixed>  $ports
     * @return list<array{key: string, name: string, protocol: string, port: int|null, allocation: int|null}>
     */
    private function extraPorts(array $ports): array
    {
        $normalised = [];
        $keys = [];

        foreach ($ports as $port) {
            if (! is_array($port)) {
                continue;
            }

            $name = mb_trim(is_string($port['name'] ?? null) ? $port['name'] : '');
            $base = Str::slug($name) !== '' ? mb_substr(Str::slug($name), 0, 24) : 'port';
            // "game" is the key of the main tunnel.
            $key = $base === 'game' ? 'game-extra' : $base;
            for ($i = 2; in_array($key, $keys, true); $i++) {
                $key = $base.'-'.$i;
            }

            $keys[] = $key;
            $protocol = $port['protocol'] ?? 'both';
            $normalised[] = [
                'key' => $key,
                'name' => $name,
                'protocol' => is_string($protocol) && in_array($protocol, ['tcp', 'udp', 'both'], true) ? $protocol : 'both',
                'port' => is_numeric($port['port'] ?? null) ? (int) $port['port'] : null,
                'allocation' => is_numeric($port['allocation'] ?? null) ? (int) $port['allocation'] : null,
            ];
        }

        return $normalised;
    }

    private function uniqueSlug(string $name): string
    {
        $base = mb_substr(Str::slug($name), 0, 24);
        if ($base === '' || ctype_digit($base)) {
            $base = 'game-'.$base;
        }

        $base = mb_trim($base, '-');
        $slug = $base;
        for ($i = 2; Game::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
