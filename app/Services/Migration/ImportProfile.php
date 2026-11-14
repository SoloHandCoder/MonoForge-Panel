<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Migration;

use Illuminate\Database\Connection;

/**
 * A source panel the importer knows how to read.
 *
 * Each profile is pinned to one release of the source panel. Mapping every
 * historical schema of every fork is not tractable; asking the operator to
 * update their old panel to its final release before importing is.
 */
abstract class ImportProfile
{
    /** Human-readable name used in output. */
    abstract public function name(): string;

    /** The source release this profile was built against. */
    abstract public function supportedVersion(): string;

    /**
     * Tables to copy, in foreign-key dependency order.
     *
     * @return TablePlan[]
     */
    abstract public function tables(): array;

    /**
     * Source tables that are deliberately never imported, with the reason shown
     * to the operator. Source tables that are neither planned nor listed here are
     * reported as "no equivalent in this panel".
     *
     * @return array<string, string>
     */
    abstract public function excludedTables(): array;

    /**
     * Caveats worth reading before starting.
     *
     * @return string[]
     */
    public function warnings(Connection $source): array
    {
        return [];
    }

    /**
     * Runs inside the import transaction before any table is copied, for rows
     * this panel needs that the source has no equivalent of.
     */
    public function beforeImport(Connection $source, Connection $target, ImportSummary $summary): void {}

    /**
     * Runs inside the import transaction after every planned table is copied,
     * for data that does not map table-to-table.
     */
    public function afterImport(Connection $source, Connection $target, ImportSummary $summary): void {}

    /**
     * Source columns holding APP_KEY-encrypted values, as table => [columns].
     * The pre-flight probe samples these to verify the source key.
     *
     * @return array<string, string[]>
     */
    public function encryptedColumns(): array
    {
        return [
            'users' => ['totp_secret'],
            'nodes' => ['daemon_token'],
            'api_keys' => ['token'],
            'database_hosts' => ['password'],
            'databases' => ['password'],
        ];
    }
}
