<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Status;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Setting;
use Throwable;

/**
 * Whether the public status page is on, and which nodes it shows under which public name.
 *
 * A node is never shown by its FQDN, address or panel name: it has a display name an
 * admin can set, by default its location's short name and "Node N" (N counts the nodes
 * of that location in the order they were added). Until an admin chooses, the nodes
 * that are public for deployment are shown.
 *
 * @phpstan-type StatusNode array{node: Node, visible: bool, name: string, default_name: string, custom_name: string}
 */
final class StatusPageSettings
{
    public const string ENABLED_KEY = 'settings::monoforge:status:enabled';

    /** JSON: {"<node id>": {"visible": bool, "name": string}}. */
    public const string NODES_KEY = 'settings::monoforge:status:nodes';

    public const int NAME_MAX_LENGTH = 40;

    public static function enabled(): bool
    {
        return self::stored(self::ENABLED_KEY) !== 'false';
    }

    /**
     * Every node with whether the status page shows it and the name it shows.
     *
     * @return list<StatusNode>
     */
    public static function nodes(): array
    {
        $preferences = self::preferences();
        $nodes = Node::query()->with('location')->orderBy('id')->get();

        /** @var array<int, int> $counts */
        $counts = [];
        $rows = [];
        foreach ($nodes as $node) {
            $counts[$node->location_id] = ($counts[$node->location_id] ?? 0) + 1;
            $default = mb_trim(sprintf('%s Node %d', (string) $node->location->short, $counts[$node->location_id]));
            $preference = $preferences[$node->id] ?? null;
            $custom = $preference['name'] ?? '';

            $rows[] = [
                'node' => $node,
                // Until the admin chooses, only nodes players can buy on (public and in the shop) are shown.
                'visible' => $preference['visible'] ?? ($node->public && $node->shop_enabled),
                'name' => $custom !== '' ? $custom : $default,
                'default_name' => $default,
                'custom_name' => $custom,
            ];
        }

        // Shown in a stable order: by location, then in the order the nodes were added.
        usort($rows, fn (array $a, array $b): int => [(string) $a['node']->location->short, $a['node']->id] <=> [(string) $b['node']->location->short, $b['node']->id]);

        return $rows;
    }

    /**
     * The nodes the status page shows.
     *
     * @return list<StatusNode>
     */
    public static function visibleNodes(): array
    {
        return array_values(array_filter(self::nodes(), fn (array $row): bool => $row['visible']));
    }

    /**
     * @param  array<int, array{visible: bool, name: string}>  $nodes  by node id; nodes left out keep their choice
     */
    public static function save(bool $enabled, array $nodes): void
    {
        $preferences = self::preferences();
        foreach ($nodes as $id => $preference) {
            $preferences[$id] = ['visible' => $preference['visible'], 'name' => mb_trim($preference['name'])];
        }

        // Nodes that were deleted since.
        $existing = Node::query()->get(['id'])->map(fn (Node $node): int => $node->id)->all();
        $preferences = array_intersect_key($preferences, array_flip($existing));
        ksort($preferences);

        Setting::put(self::ENABLED_KEY, $enabled ? 'true' : 'false');
        Setting::put(self::NODES_KEY, json_encode((object) $preferences, JSON_THROW_ON_ERROR));

        Cache::forget(PublicStatusService::CACHE_KEY);
    }

    /**
     * @return array<int, array{visible: bool, name: string}>
     */
    private static function preferences(): array
    {
        $decoded = json_decode(self::stored(self::NODES_KEY), true);
        if (! is_array($decoded)) {
            return [];
        }

        $preferences = [];
        foreach ($decoded as $id => $preference) {
            if (! is_numeric($id) || ! is_array($preference)) {
                continue;
            }

            $name = $preference['name'] ?? '';
            $preferences[(int) $id] = [
                'visible' => ($preference['visible'] ?? false) === true,
                'name' => is_string($name) ? mb_substr(mb_trim($name), 0, self::NAME_MAX_LENGTH) : '',
            ];
        }

        return $preferences;
    }

    private static function stored(string $key): string
    {
        try {
            return (string) Setting::cached($key);
        } catch (Throwable) {
            return '';
        }
    }
}
