<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\Migration;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Services\Migration\M12LabsOAuthLinkImporter;
use RuntimeException;
use Throwable;

#[Description('Import the Discord and Google account links from an m12labs panel database.')]
#[Signature('p:migrate:m12labs-oauth-links
    {--host=127.0.0.1 : m12labs database host}
    {--port=3306 : m12labs database port}
    {--database= : m12labs database name}
    {--username= : m12labs database username}
    {--password= : m12labs database password (prefer the SOURCE_DB_PASSWORD environment variable)}
    {--dry-run : Report what would be imported without writing anything}')]
class ImportM12LabsOAuthLinksCommand extends Command
{
    /** Read from the environment in preference to the command line, which `ps` shows. */
    private const string PASSWORD_ENV = 'SOURCE_DB_PASSWORD';

    private const string CONNECTION = 'm12labs_oauth_import_source';

    public function handle(): int
    {
        try {
            $source = $this->connectToSource();
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());

            return self::FAILURE;
        }

        if (! $source->getSchemaBuilder()->hasTable('user_oauth_accounts')) {
            $this->error('The m12labs database has no user_oauth_accounts table.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        // Each link carries its m12labs user's email, so it is only written to the same person here.
        $rows = $source->table('user_oauth_accounts as links')
            ->leftJoin('users', 'users.id', '=', 'links.user_id')
            ->select(['links.*', 'users.email as user_email'])
            ->orderBy('links.id')
            ->cursor();
        $counts = (new M12LabsOAuthLinkImporter)->import($rows, $dryRun);

        $this->info($dryRun ? 'Dry run finished. Nothing was written.' : 'Import finished.');
        $this->table(['Outcome', 'Links'], array_map(fn (string $outcome, int $count): array => [$outcome, $count], array_keys($counts), $counts));

        return self::SUCCESS;
    }

    private function connectToSource(): Connection
    {
        $database = $this->option('database') ?: $this->askRequired('m12labs database name');
        $username = $this->option('username') ?: $this->askRequired('m12labs database username');
        $password = $this->option('password');
        if (! is_string($password) || $password === '') {
            $password = getenv(self::PASSWORD_ENV);
        }

        if ((! is_string($password) || $password === '') && $this->input->isInteractive()) {
            $password = $this->secret('m12labs database password');
        }

        Config::set('database.connections.'.self::CONNECTION, [
            'driver' => 'mysql',
            'host' => $this->option('host'),
            'port' => $this->option('port'),
            'database' => $database,
            'username' => $username,
            'password' => is_string($password) ? $password : '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'strict' => false,
        ]);

        DB::purge(self::CONNECTION);
        $connection = DB::connection(self::CONNECTION);

        try {
            $connection->getPdo();
        } catch (Throwable $throwable) {
            throw new RuntimeException('Could not connect to the m12labs database: '.$throwable->getMessage(), (int) $throwable->getCode(), $throwable);
        }

        return $connection;
    }

    private function askRequired(string $question): string
    {
        throw_unless($this->input->isInteractive(), RuntimeException::class, "Missing required option for: {$question}.");

        $answer = $this->ask($question);

        return is_string($answer) ? $answer : '';
    }
}
