<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Migration\Profiles;

use Illuminate\Database\Connection;
use Pterodactyl\Services\Migration\Cell;
use Pterodactyl\Services\Migration\ImportContext;
use Pterodactyl\Services\Migration\ImportProfile;
use Pterodactyl\Services\Migration\ImportSummary;
use Pterodactyl\Services\Migration\TablePlan;
use Pterodactyl\Services\Tags\TagBackfiller;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * m12labs panel (macery12/m12labs-panel), at its final release before the
 * project was archived. m12labs is itself a Pterodactyl fork, so the core
 * tables line up; the differences handled here are:
 *
 *  - nodes: m12labs renamed the port and base-path columns, split ports into
 *    "listen" and "public", and removed locations entirely.
 *  - servers: m12labs stores whether the OOM killer is enabled, Pterodactyl
 *    whether it is disabled.
 *  - database hosts: m12labs links a host from the node side
 *    (nodes.database_host_id), Pterodactyl from the host side (database_hosts.node_id).
 *  - admin access: m12labs has access profiles (admin_roles); MonoForge only has
 *    root administrators.
 *  - billing, extensions, tickets, custom domains and the rest of m12labs' own
 *    features have no equivalent here yet and are left in the old database.
 */
final class M12LabsProfile extends ImportProfile
{
    private const string LOCATION_SHORT = 'm12labs';

    private const string BILLING = 'billing is not part of MonoForge yet';

    private ?int $locationId = null;

    public function name(): string
    {
        return 'm12labs';
    }

    public function supportedVersion(): string
    {
        return '4.x (final release)';
    }

    public function tables(): array
    {
        $rewrap = fn (?string $value, array $row, ImportContext $context): ?string => $context->rewrap($value);

        return [
            new TablePlan(
                table: 'users',
                drops: [
                    'admin_role_id' => 'MonoForge has no access profiles; only root administrators keep admin access',
                    'stripe_id' => self::BILLING,
                    'pm_type' => self::BILLING,
                    'pm_last_four' => self::BILLING,
                    'trial_ends_at' => self::BILLING,
                    'state' => self::BILLING,
                    'email_verified_at' => 'MonoForge does not track email verification',
                    'email_verification_token' => 'MonoForge does not track email verification',
                    'recovery_code' => 'm12labs account recovery codes have no equivalent; 2FA recovery tokens are imported',
                    'recovery_code_seen' => 'm12labs account recovery codes have no equivalent; 2FA recovery tokens are imported',
                ],
                transforms: [
                    'totp_secret' => $rewrap,
                    'language' => fn (?string $value): string => $value ?: 'en',
                ],
            ),
            new TablePlan(
                table: 'api_keys',
                drops: [
                    'acl_enforced' => 'MonoForge always enforces application key permissions',
                    'admin_role_id' => 'MonoForge has no access profiles',
                ],
                transforms: ['token' => $rewrap],
                // m12labs also stores internal daemon keys (types 3 and 4); MonoForge
                // only has account (1) and application (2) keys.
                filter: fn (array $row): bool => in_array(Cell::int($row['key_type'] ?? null), [1, 2], true),
            ),
            new TablePlan(table: 'recovery_tokens'),
            new TablePlan(table: 'user_ssh_keys'),

            new TablePlan(table: 'nests'),
            new TablePlan(table: 'eggs'),
            new TablePlan(
                table: 'egg_variables',
                drops: ['field_type' => "MonoForge works out the input type from the variable's rules"],
            ),

            new TablePlan(
                table: 'database_hosts',
                transforms: ['password' => $rewrap],
            ),
            new TablePlan(
                table: 'nodes',
                // The panel reaches Wings, and users reach SFTP, on the public ports,
                // so those become MonoForge's single port per service. A node whose
                // listen port differs (behind a proxy) is warned about up front.
                renames: [
                    'public_port_http' => 'daemonListen',
                    'public_port_sftp' => 'daemonSFTP',
                    'daemon_base' => 'daemonBase',
                ],
                drops: [
                    'wings_type' => 'detected again from the node when it next connects',
                    'wings_version' => 'detected again from the node when it next connects',
                    'wings_detected_at' => 'detected again from the node when it next connects',
                    'deployable' => self::BILLING,
                    'deployable_free' => self::BILLING,
                    'price_multiplier' => self::BILLING,
                    'price_multiplier_description' => self::BILLING,
                ],
                converted: [
                    'listen_port_http' => 'MonoForge uses the public port; see the warning above for nodes where the two differ',
                    'listen_port_sftp' => 'MonoForge uses the public port; see the warning above for nodes where the two differ',
                    'database_host_id' => 'moved to database_hosts.node_id',
                ],
                defaults: [
                    'location_id' => fn (): int => $this->locationId ?? throw new RuntimeException('The import location was not created.'),
                ],
                transforms: ['daemon_token' => $rewrap],
            ),
            new TablePlan(table: 'allocations'),

            new TablePlan(
                table: 'servers',
                // Same setting, opposite polarity.
                renames: ['oom_killer' => 'oom_disabled'],
                drops: [
                    'billing_amount' => self::BILLING,
                    'billing_days' => self::BILLING,
                    'billing_order_id' => self::BILLING,
                    'billing_product_id' => self::BILLING,
                    'renewal_date' => self::BILLING,
                    'last_plan_change_at' => self::BILLING,
                    'pending_plan_change_order_id' => self::BILLING,
                    'scheduled_billing_product_id' => self::BILLING,
                    'scheduled_plan_change_at' => self::BILLING,
                    'scheduled_plan_change_last_error' => self::BILLING,
                    'scheduled_plan_change_retry_at' => self::BILLING,
                    'scheduled_plan_change_snapshot' => self::BILLING,
                    'deletion_scheduled_at' => 'scheduled deletions are not carried over; the server is kept',
                    'deletion_scheduled_by' => 'scheduled deletions are not carried over; the server is kept',
                    'deletion_canceled_at' => 'scheduled deletions are not carried over; the server is kept',
                    'subdomain_limit' => 'custom domains are not part of MonoForge yet',
                    'subuser_limit' => 'MonoForge does not limit subusers per server',
                    'mods_enabled' => 'the m12labs mod installer is not part of MonoForge yet',
                ],
                transforms: [
                    'oom_disabled' => fn (mixed $value): int => $value ? 0 : 1,
                    'description' => fn (?string $value): string => $value ?? '',
                ],
            ),
            new TablePlan(table: 'server_variables'),
            $this->subusersPlan(),
            new TablePlan(
                table: 'databases',
                transforms: ['password' => $rewrap],
            ),
            new TablePlan(
                table: 'backups',
                drops: ['upload_size' => 'MonoForge does not record the uploaded size of a backup'],
            ),
            new TablePlan(table: 'schedules'),
            new TablePlan(table: 'tasks'),
            new TablePlan(table: 'server_transfers'),

            new TablePlan(table: 'mounts'),
            new TablePlan(table: 'egg_mount'),
            new TablePlan(table: 'mount_node'),
            new TablePlan(table: 'mount_server'),

            new TablePlan(
                table: 'activity_logs',
                drops: [
                    'is_admin' => 'MonoForge does not split the activity log by area',
                    'scope' => 'MonoForge does not split the activity log by area',
                    'server_id' => "the server is still linked through the log entry's subjects",
                ],
                transforms: ['properties' => fn (?string $value): string => $value ?? '{}'],
                group: TablePlan::GROUP_LOGS,
            ),
            new TablePlan(table: 'activity_log_subjects', group: TablePlan::GROUP_LOGS),
        ];
    }

    public function excludedTables(): array
    {
        return [
            'settings' => 'panel settings are keyed differently and encrypted with the old APP_KEY; configure MonoForge from scratch',
            'sessions' => 'login sessions do not survive a move; users sign in again',
            'user_sessions' => 'login sessions do not survive a move; users sign in again',
            'jobs' => 'queued jobs belong to the old panel',
            'failed_jobs' => 'queued jobs belong to the old panel',
            'notifications' => 'delivered notifications are not carried over',
            'password_resets' => 'pending reset links stop working after the move',
            'password_reset_tokens' => 'pending reset links stop working after the move',
            'migrations' => 'migration history belongs to each panel',
            'audit_logs' => 'superseded by the activity log (import it with --with-logs)',
            'api_logs' => 'API request logs are not carried over',
            'tasks_log' => 'old scheduler history that nothing reads',
            'admin_roles' => 'MonoForge has no access profiles; only root administrators keep admin access',
            'user_oauth_accounts' => 'imported separately with php artisan p:migrate:m12labs-oauth-links',
        ];
    }

    public function warnings(Connection $source): array
    {
        $warnings = [
            'Update m12labs to its final release before importing. This importer was built against that schema and refuses to run if a required column is missing.',
            'Billing, extensions, tickets, custom domains, themes and other m12labs-only features are not imported; they stay in the old database.',
            'All imported nodes are placed in a new location named "m12labs", since m12labs had no locations.',
        ];

        $admins = $source->table('users')
            ->where('root_admin', false)
            ->whereNotNull('admin_role_id')
            ->pluck('username');

        if ($admins->isNotEmpty()) {
            $warnings[] = sprintf(
                '%d user(s) had admin access through an m12labs access profile and will be regular users in MonoForge: %s. Make them root administrators afterwards if they still need admin access.',
                $admins->count(),
                $admins->take(20)->implode(', ').($admins->count() > 20 ? ', …' : ''),
            );
        }

        $proxied = $source->table('nodes')
            ->whereColumn('listen_port_http', '!=', 'public_port_http')
            ->orWhereColumn('listen_port_sftp', '!=', 'public_port_sftp')
            ->get(['name', 'listen_port_http', 'public_port_http', 'listen_port_sftp', 'public_port_sftp']);

        foreach ($proxied as $node) {
            $publicHttp = Cell::int(Cell::column($node, 'public_port_http'));
            $publicSftp = Cell::int(Cell::column($node, 'public_port_sftp'));

            $warnings[] = sprintf(
                'Node "%s" listens on %d/%d but is reached on %d/%d. MonoForge keeps one port per service and will use the public ones (%d/%d). '
                .'Your running wings-rs config is not touched, but if you regenerate it from MonoForge, set the listen ports back by hand.',
                Cell::string(Cell::column($node, 'name')),
                Cell::int(Cell::column($node, 'listen_port_http')),
                Cell::int(Cell::column($node, 'listen_port_sftp')),
                $publicHttp,
                $publicSftp,
                $publicHttp,
                $publicSftp,
            );
        }

        return $warnings;
    }

    public function beforeImport(Connection $source, Connection $target, ImportSummary $summary): void
    {
        $existing = $target->table('locations')->where('short', self::LOCATION_SHORT)->value('id');

        $this->locationId = $existing !== null ? Cell::int($existing) : $target->table('locations')->insertGetId([
            'short' => self::LOCATION_SHORT,
            'long' => 'Nodes imported from m12labs',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary->note('Imported nodes are in the "'.self::LOCATION_SHORT.'" location.');
    }

    public function afterImport(Connection $source, Connection $target, ImportSummary $summary): void
    {
        $this->linkDatabaseHosts($source, $target, $summary);

        $attached = (new TagBackfiller($target))->backfill();
        $summary->note("Tagged imported eggs from their nests ({$attached} tag link(s)).");

        $this->assertUuidsValid($target);
    }

    /**
     * MonoForge enforces one subuser row per user and server. Keep the first and
     * skip any later duplicates instead of failing the whole import on them.
     */
    private function subusersPlan(): TablePlan
    {
        $seen = [];

        return new TablePlan(
            table: 'subusers',
            drops: ['disabled_extensions' => 'm12labs extensions are not carried over'],
            filter: function (array $row) use (&$seen): bool {
                $key = Cell::int($row['server_id'] ?? null).':'.Cell::int($row['user_id'] ?? null);
                if (isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            },
        );
    }

    /**
     * m12labs points nodes at a database host; MonoForge points a host at one
     * node. A host shared by several nodes is linked to the first of them.
     */
    private function linkDatabaseHosts(Connection $source, Connection $target, ImportSummary $summary): void
    {
        /** @var array<int, list<array{id: int, name: string}>> $links host id => nodes, in id order */
        $links = [];
        foreach ($source->table('nodes')->whereNotNull('database_host_id')->orderBy('id')->get(['id', 'name', 'database_host_id']) as $node) {
            $links[Cell::int(Cell::column($node, 'database_host_id'))][] = [
                'id' => Cell::int(Cell::column($node, 'id')),
                'name' => Cell::string(Cell::column($node, 'name')),
            ];
        }

        foreach ($links as $hostId => $nodes) {
            $first = $nodes[0];
            $target->table('database_hosts')->where('id', $hostId)->update(['node_id' => $first['id']]);

            if (count($nodes) > 1) {
                $summary->warn(sprintf(
                    'Database host #%d was linked to %d nodes in m12labs (%s). MonoForge links a host to one node, so it is now linked to "%s" only.',
                    $hostId,
                    count($nodes),
                    implode(', ', array_column($nodes, 'name')),
                    $first['name'],
                ));
            }
        }

        if ($links !== []) {
            $summary->note(sprintf('Linked %d database host(s) to their nodes.', count($links)));
        }
    }

    /**
     * Wings stores every server's files under its UUID, so a malformed one would
     * leave a server MonoForge cannot reach. Catch it before committing.
     */
    private function assertUuidsValid(Connection $target): void
    {
        foreach (['servers', 'nodes', 'users'] as $table) {
            $bad = $target->table($table)->pluck('uuid')
                ->map(fn (mixed $uuid): string => Cell::nullableString($uuid) ?? '')
                ->reject(fn (string $uuid): bool => Uuid::isValid($uuid));

            if ($bad->isNotEmpty()) {
                throw new RuntimeException("{$bad->count()} row(s) in {$table} have an invalid UUID: ".$bad->take(5)->implode(', '));
            }
        }
    }
}
