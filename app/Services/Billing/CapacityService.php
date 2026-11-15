<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Keeps the shop within the memory, disk and CPU the owner is willing to sell. Only
 * servers sold through the shop count: those with an active or suspended row in
 * server_subscriptions. A limit of 0 is no limit.
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
     * Whether a server with these resources still fits within every limit.
     *
     * @param  array<string, int>  $resources  memory (MB), disk (MB) and cpu (percent); other keys are ignored
     */
    public function canProvision(array $resources): bool
    {
        return self::fits(ShopSettings::limits(), $this->used(), $resources);
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
        $row = DB::table('servers')
            ->join('server_subscriptions', 'server_subscriptions.server_id', '=', 'servers.id')
            ->whereIn('server_subscriptions.status', self::COUNTED_STATUSES)
            ->selectRaw('COALESCE(SUM(servers.memory), 0) AS memory, COALESCE(SUM(servers.disk), 0) AS disk, COALESCE(SUM(servers.cpu), 0) AS cpu')
            ->first();

        return [
            'memory' => $this->toInt($row?->memory),
            'disk' => $this->toInt($row?->disk),
            'cpu' => $this->toInt($row?->cpu),
        ];
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
