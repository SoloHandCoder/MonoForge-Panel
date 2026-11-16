<?php

declare(strict_types=1);

namespace Pterodactyl\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Node;

/**
 * @template TModel of Allocation
 *
 * @extends Builder<TModel>
 */
class AllocationBuilder extends Builder
{
    public function unassigned(): static
    {
        $this->whereNull('server_id');

        return $this;
    }

    /**
     * Allocations on any of the given nodes; an empty list leaves the query unrestricted.
     *
     * @param  array<int>  $nodes
     */
    public function onNodes(array $nodes): static
    {
        $this->when($nodes !== [], fn (Builder $q) => $q->whereIn('node_id', $nodes));

        return $this;
    }

    /**
     * Allocations whose port is one of the given ports or falls inside one of the
     * given [start, end] ranges; with neither given the query is unrestricted.
     *
     * @param  list<int>  $ports
     * @param  list<array{int, int}>  $ranges
     */
    public function onPorts(array $ports, array $ranges = []): static
    {
        if ($ports === [] && $ranges === []) {
            return $this;
        }

        $this->where(function (Builder $inner) use ($ports, $ranges): void {
            if ($ports !== []) {
                $inner->orWhereIn('port', $ports);
            }

            foreach ($ranges as $range) {
                $inner->orWhereBetween('port', $range);
            }
        });

        return $this;
    }

    /**
     * Orders allocations the game gateway forwards before those it does not, on the given nodes
     * in gateway mode, so a new server there is reachable by players when it can be. Nodes
     * without the gateway are unaffected.
     *
     * @param  array<int>  $nodes
     */
    public function forwardedFirst(array $nodes): static
    {
        $clauses = [];
        $bindings = [];
        foreach (Node::query()->whereIn('id', $nodes)->where('gateway_enabled', true)->get() as $node) {
            if (! $node->hasGateway()) {
                continue;
            }

            $ranges = [];
            $bindings[] = $node->id;
            foreach ($node->gatewayRanges() as [$from, $to]) {
                $ranges[] = 'port BETWEEN ? AND ?';
                $bindings[] = $from;
                $bindings[] = $to;
            }

            $clauses[] = '(node_id = ? AND ('.implode(' OR ', $ranges).'))';
        }

        if ($clauses !== []) {
            $this->orderByRaw('CASE WHEN '.implode(' OR ', $clauses).' THEN 0 ELSE 1 END', $bindings);
        }

        return $this;
    }

    /**
     * Allocations on node/IP pairs that no server uses yet.
     *
     * @param  array<int>  $nodes  restrict the occupied-IP lookup to these nodes
     */
    public function onDedicatedIp(array $nodes = []): static
    {
        $occupied = Allocation::query()
            ->selectRaw('CONCAT_WS("-", node_id, ip)')
            ->whereNotNull('server_id')
            ->onNodes($nodes);

        $this->whereNotIn($this->raw('CONCAT_WS("-", node_id, ip)'), $occupied);

        return $this;
    }
}
