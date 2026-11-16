<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Games;

use Illuminate\Database\ConnectionInterface;

/**
 * Files the eggs no game claims under the built-in game the panel detects them as, so Admin >
 * Games starts pre-filled. Insert-only and idempotent: an egg that already belongs to a game is
 * never moved, so it is safe to run again (the games migration runs it once).
 */
final readonly class GameEggBackfiller
{
    public function __construct(private ConnectionInterface $db) {}

    /** @return int the number of eggs filed under a game */
    public function backfill(): int
    {
        $games = [];
        foreach ($this->db->table('games')->where('built_in', true)->get(['id', 'slug']) as $game) {
            $id = filter_var($game->id, FILTER_VALIDATE_INT);
            if ($id !== false && is_string($game->slug)) {
                $games[$game->slug] = $id;
            }
        }

        $tags = [];
        $rows = $this->db->table('taggables')
            ->join('tags', 'tags.id', '=', 'taggables.tag_id')
            ->where('taggables.taggable_type', 'egg')
            ->get(['taggables.taggable_id', 'tags.slug']);
        foreach ($rows as $row) {
            $eggId = filter_var($row->taggable_id, FILTER_VALIDATE_INT);
            if ($eggId !== false && is_string($row->slug)) {
                $tags[$eggId][] = $row->slug;
            }
        }

        $filed = 0;
        foreach ($this->db->table('eggs')->whereNull('game_id')->orderBy('id')->get(['id', 'name']) as $egg) {
            $eggId = filter_var($egg->id, FILTER_VALIDATE_INT);
            if ($eggId === false || ! is_string($egg->name)) {
                continue;
            }

            $slug = GameDetector::detect($egg->name, $tags[$eggId] ?? []);
            if ($slug !== null && isset($games[$slug])) {
                $filed += $this->db->table('eggs')->where('id', $eggId)->whereNull('game_id')->update(['game_id' => $games[$slug]]);
            }
        }

        return $filed;
    }
}
