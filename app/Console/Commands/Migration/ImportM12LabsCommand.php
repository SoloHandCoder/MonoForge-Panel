<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\Migration;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Services\Migration\ImportContext;
use Pterodactyl\Services\Migration\ImportSummary;
use Pterodactyl\Services\Migration\PanelImportService;
use Pterodactyl\Services\Migration\Profiles\M12LabsProfile;
use Pterodactyl\Services\Migration\TablePlan;
use RuntimeException;
use Throwable;

#[Description('Import users, nodes, servers and the rest of the core panel data from an m12labs panel database.')]
#[Signature('p:migrate:m12labs
    {--host=127.0.0.1 : m12labs database host}
    {--port=3306 : m12labs database port}
    {--database= : m12labs database name}
    {--username= : m12labs database username}
    {--password= : m12labs database password (prefer the SOURCE_DB_PASSWORD environment variable)}
    {--source-key= : m12labs APP_KEY from its .env (prefer the SOURCE_APP_KEY environment variable)}
    {--with-logs : Also import the activity log}
    {--dry-run : Run the whole import in a transaction that is rolled back, and report what would happen}
    {--assume-yes : Answer the confirmation prompts with yes, for unattended runs}')]
class ImportM12LabsCommand extends Command
{
    /**
     * Read from the environment in preference to the command line, because
     * command-line arguments are visible to every user on the machine via `ps`.
     */
    private const string PASSWORD_ENV = 'SOURCE_DB_PASSWORD';

    private const string APP_KEY_ENV = 'SOURCE_APP_KEY';

    private const string CONNECTION = 'm12labs_import_source';

    public function handle(): int
    {
        $this->printPreamble();

        $profile = new M12LabsProfile;
        $groups = [TablePlan::GROUP_CORE];
        if ($this->option('with-logs')) {
            $groups[] = TablePlan::GROUP_LOGS;
        }

        try {
            $context = $this->makeContext();
            $source = $this->connectToSource();
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());

            return self::FAILURE;
        }

        $service = new PanelImportService(DB::connection());
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Source    : {$profile->name()} {$profile->supportedVersion()} on {$this->option('host')}/{$source->getDatabaseName()}");
        $this->info('Importing : '.implode(', ', $groups));
        $this->newLine();

        try {
            $preflight = $service->preflight($profile, $source, $context, $groups);
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());

            return self::FAILURE;
        }

        $this->reportBeforehand($preflight);

        if (! $dryRun && ! $this->confirmRun()) {
            $this->warn('Aborted. Nothing was written.');

            return self::FAILURE;
        }

        $this->line($dryRun ? 'Running the import as a dry run…' : 'Importing…');

        try {
            $summary = $service->import($profile, $source, $context, $groups, $dryRun);
        } catch (Throwable $throwable) {
            $this->newLine();
            $this->error('The import failed and was rolled back. The MonoForge database is unchanged.');
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->reportResult($summary, $dryRun);
        $this->printNextSteps($dryRun);

        return self::SUCCESS;
    }

    private function printPreamble(): void
    {
        $this->newLine();
        $this->warn('  m12labs → MonoForge import (experimental)');
        $this->newLine();
        $this->line("  This copies data from an m12labs panel database into THIS panel's database.");
        $this->line('  The m12labs database is only read, never changed. Back up the MonoForge');
        $this->line('  database first, and start with --dry-run.');
        $this->newLine();
    }

    private function connectToSource(): Connection
    {
        $database = $this->option('database') ?: $this->askRequired('m12labs database name');
        $username = $this->option('username') ?: $this->askRequired('m12labs database username');
        $password = $this->secretOption('password', self::PASSWORD_ENV, 'm12labs database password');

        Config::set('database.connections.'.self::CONNECTION, [
            'driver' => 'mysql',
            'host' => $this->option('host'),
            'port' => $this->option('port'),
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict' => false,
        ]);

        DB::purge(self::CONNECTION);
        $connection = DB::connection(self::CONNECTION);

        try {
            $connection->getPdo();
        } catch (Throwable $throwable) {
            throw new RuntimeException('Could not connect to the m12labs database: '.$throwable->getMessage(), $throwable->getCode(), $throwable);
        }

        return $connection;
    }

    private function makeContext(): ImportContext
    {
        $key = $this->secretOption('source-key', self::APP_KEY_ENV, 'm12labs APP_KEY (from its .env file)');
        throw_unless($key, RuntimeException::class, 'The m12labs APP_KEY is required: node tokens and stored passwords are encrypted with it.');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(mb_substr($key, 7), true);
        }

        // The decoded key is raw bytes, so count bytes ('8bit'), not characters.
        $cipher = match (mb_strlen($key, '8bit')) {
            32 => 'AES-256-CBC',
            16 => 'AES-128-CBC',
            default => throw new RuntimeException('That is not a valid Laravel APP_KEY. Expected a 16 or 32 byte key, usually written as base64:…'),
        };

        return new ImportContext(new Encrypter($key, $cipher), resolve(Encrypter::class));
    }

    private function reportBeforehand(ImportSummary $preflight): void
    {
        foreach ($preflight->warnings as $warning) {
            $this->warn('  ! '.wordwrap($warning, 100, "\n    "));
            $this->newLine();
        }

        if ($preflight->skipped !== []) {
            $this->line('Tables in m12labs that will NOT be imported:');
            foreach ($preflight->skipped as $table => $reason) {
                $this->line("  - {$table}: {$reason}");
            }

            $this->newLine();
        }
    }

    private function confirmRun(): bool
    {
        if ($this->option('assume-yes')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Refusing to import unattended without --assume-yes.');

            return false;
        }

        if (! $this->confirm("Have you backed up THIS panel's database?", false)) {
            $this->line('Back it up first, then run this again. A dry run is safe meanwhile:');
            $this->line('  php artisan p:migrate:m12labs --dry-run');

            return false;
        }

        return $this->confirm('Import now?', false);
    }

    private function reportResult(ImportSummary $summary, bool $dryRun): void
    {
        $this->newLine();
        $this->info($dryRun ? 'Dry run finished. Nothing was written.' : 'Import finished.');
        $this->newLine();

        $rows = [];
        foreach ($summary->copied as $table => $count) {
            $rows[] = [$table, $count];
        }

        $this->table(['Table', $dryRun ? 'Rows (would be) copied' : 'Rows copied'], $rows);

        foreach ($summary->filtered as $table => $count) {
            $this->line("  {$table}: skipped {$count} row(s) MonoForge cannot use");
        }

        $lossy = $summary->lossyColumns();
        if ($lossy !== []) {
            $this->newLine();
            $this->line('Data left behind (columns with values that have no home in MonoForge):');
            foreach ($lossy as $drop) {
                $this->line("  - {$drop['table']}.{$drop['column']} ({$drop['rows']} rows): {$drop['reason']}");
            }
        }

        foreach ($summary->warnings as $warning) {
            $this->newLine();
            $this->warn('  ! '.wordwrap($warning, 100, "\n    "));
        }

        if ($summary->notes !== []) {
            $this->newLine();
            foreach ($summary->notes as $note) {
                $this->line("  · {$note}");
            }
        }
    }

    private function printNextSteps(bool $dryRun): void
    {
        $this->newLine();

        if ($dryRun) {
            $this->line('Happy with that? Run the same command without --dry-run.');

            return;
        }

        $this->line('Next steps:');
        $this->line('  1. Sign in with an imported root administrator account.');
        $this->line("  2. Point each node's wings-rs config at this panel (remote: in its config.yml) and restart it.");
        $this->line('     Node tokens were carried over, so the existing token keeps working.');
        $this->line("  3. Check a server's console and files, then retire the m12labs panel.");
    }

    private function askRequired(string $question): string
    {
        throw_unless($this->input->isInteractive(), RuntimeException::class, "Missing required option for: {$question}.");

        $answer = $this->ask($question);

        return is_string($answer) ? $answer : '';
    }

    private function secretOption(string $option, string $env, string $question): ?string
    {
        $value = $this->option($option);
        if (! is_string($value) || $value === '') {
            $value = getenv($env);
        }

        if ((! is_string($value) || $value === '') && $this->input->isInteractive()) {
            $value = $this->secret($question);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
