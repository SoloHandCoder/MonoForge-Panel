<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Games;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Game;
use Pterodactyl\Models\Server;

/**
 * Matches the extra ports a game defines in Admin > Games (such as Simple Voice Chat) to a
 * server's allocations other than the primary: first by its port, otherwise by its position
 * among them (1 is the first extra allocation). An allocation serves one port. Both the playit
 * tunnels and the game gateway addresses come from this.
 */
final class ExtraPortPlanner
{
    /**
     * @return array<string, array{name: string, protocol: string, allocation_id: int, port: int}>
     */
    public static function plan(Server $server, Game $game): array
    {
        $ports = $game->extraPorts();
        if ($ports === []) {
            return [];
        }

        $extras = Allocation::query()->where('server_id', $server->id)->where('id', '!=', $server->allocation_id)->orderBy('id')->get()->values();
        $planned = [];
        $used = [];

        foreach ($ports as $port) {
            $allocation = null;
            if ($port['port'] !== null) {
                $allocation = $extras->first(fn (Allocation $candidate): bool => (int) $candidate->port === $port['port'] && ! in_array($candidate->id, $used, true));
            }

            if (! $allocation instanceof Allocation && $port['allocation'] !== null) {
                $allocation = $extras->get($port['allocation'] - 1);
                if ($allocation instanceof Allocation && in_array($allocation->id, $used, true)) {
                    $allocation = null;
                }
            }

            if ($allocation instanceof Allocation) {
                $used[] = $allocation->id;
                $planned[$port['key']] = ['name' => $port['name'], 'protocol' => $port['protocol'], 'allocation_id' => $allocation->id, 'port' => (int) $allocation->port];
            }
        }

        return $planned;
    }
}
