<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Node;

/**
 * Keeps the shop within the memory, disk and CPU the owner is willing to sell. Only
 * servers sold through the shop count: those with an active or suspended row in
 * server_subscriptions. A limit of 0 is no limit.
 *
 * Each node may also have its own shop limits (nodes.shop_limit_*), counted over the shop
 * servers on that node only, and a purchase has to fit both. The node's own Pterodactyl memory
 * and disk (and their overallocation) never decide what the shop sells.
 */
class CapacityService
{
    /** Subscription states whose servers still hold their resources. */
    public const array COUNTED_STATUSES = ['active', 'suspended'];

    private const array KEYS = ['memory', 'disk', 'cpu'];

    /**
     * Whether adding the resources to what is used stays within every non-zero limit.
     *
     * @param  array{memory: int, disk: int, cpu: int}  $limits
     * @param  array{memory: int, disk: int, cpu: int}  $used
     * @param  array<string, int>  $resources
     */
    public static function fits(array $limits, array $used, array $resources): bool
    {
        foreach (self::KEYS as $key) {
            if ($limits[$key] > 0 && $limits[$key] < $used[$key] + max(0, (int) ($resources[$key] ?? 0))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The shop limits of one node; 0 means no limit.
     *
     * @return array{memory: int, disk: int, cpu: int}
     */
    public static function nodeLimits(Node $node): array
    {
        return [
            'memory' => max(0, (int) $node->shop_limit_memory_mb),
            'disk' => max(0, (int) $node->shop_limit_disk_mb),
            'cpu' => max(0, (int) $node->shop_limit_cpu_percent),
        ];
    }

    /**
     * Whether a server with these resources still fits within every shop-wide limit.
     *
     * @param  array<string, int>  $resources  memory (MB), disk (MB) and cpu (percent); other keys are ignored
     */
    public function canProvision(array $resources): bool
    {
        return self::fits(ShopSettings::limits(), $this->used(), $resources);
    }

    /**
     * Whether a server with these resources still fits within the node's own shop limits. The
     * shop-wide limits are canProvision()'s job.
     *
     * @param  array<string, int>  $resources  memory (MB), disk (MB) and cpu (percent); other keys are ignored
     */
    public function canProvisionOn(Node $node, array $resources): bool
    {
        $limits = self::nodeLimits($node);
        if (max($limits) === 0) {
            return true;
        }

        return self::fits($limits, $this->usedOn($node->id), $resources);
    }

    /**
     * What is left to sell of each resource, or null where there is no limit.
     *
     * @return array{memory: int|null, disk: int|null, cpu: int|null}
     */
    public function remaining(): array
    {
        $limits = ShopSettings::limits();
        $used = $this->used();
        $remaining = [];

        foreach (self::KEYS as $key) {
            $remaining[$key] = $limits[$key] > 0 ? max(0, $limits[$key] - $used[$key]) : null;
        }

        /** @var array{memory: int|null, disk: int|null, cpu: int|null} $remaining */
        return $remaining;
    }

    /**
     * The memory, disk and CPU held by shop servers right now.
     *
     * @return array{memory: int, disk: int, cpu: int}
     */
    public function used(): array
    {
        return $this->row($this->usage()->first());
    }

    /**
     * The memory, disk and CPU held by the shop servers on one node.
     *
     * @return array{memory: int, disk: int, cpu: int}
     */
    public function usedOn(int $nodeId): array
    {
        return $this->row($this->usage()->where('servers.node_id', $nodeId)->first());
    }

    /**
     * What the shop servers hold on each node, by node id; nodes without any are left out.
     *
     * @return array<int, array{memory: int, disk: int, cpu: int}>
     */
    public function usedByNode(): array
    {
        $used = [];
        foreach ($this->usage()->addSelect('servers.node_id')->groupBy('servers.node_id')->get() as $row) {
            $used[$this->toInt($row->node_id ?? null)] = $this->row($row);
        }

        return $used;
    }

    /** The shop servers' memory, disk and CPU, summed. */
    private function usage(): Builder
    {
        return DB::table('servers')
            ->join('server_subscriptions', 'server_subscriptions.server_id', '=', 'servers.id')
            ->whereIn('server_subscriptions.status', self::COUNTED_STATUSES)
            ->selectRaw('COALESCE(SUM(servers.memory), 0) AS memory, COALESCE(SUM(servers.disk), 0) AS disk, COALESCE(SUM(servers.cpu), 0) AS cpu');
    }

    /**
     * @return array{memory: int, disk: int, cpu: int}
     */
    private function row(?object $row): array
    {
        return [
            'memory' => $this->toInt($row->memory ?? null),
            'disk' => $this->toInt($row->disk ?? null),
            'cpu' => $this->toInt($row->cpu ?? null),
        ];
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
