<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\Migration;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Enum\OAuthProvider;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserOAuthAccount;
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
        $counts = ['imported' => 0, 'already linked' => 0, 'missing user' => 0, 'conflict' => 0, 'invalid' => 0];

        foreach ($source->table('user_oauth_accounts')->orderBy('id')->cursor() as $row) {
            $outcome = $this->importRow((array) $row, $dryRun);
            $counts[$outcome]++;
        }

        $this->info($dryRun ? 'Dry run finished. Nothing was written.' : 'Import finished.');
        $this->table(['Outcome', 'Links'], array_map(fn (string $outcome, int $count): array => [$outcome, $count], array_keys($counts), $counts));

        return self::SUCCESS;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return 'imported'|'already linked'|'missing user'|'conflict'|'invalid'
     */
    private function importRow(array $row, bool $dryRun): string
    {
        $provider = OAuthProvider::tryFrom(is_string($row['provider'] ?? null) ? $row['provider'] : '');
        $userId = filter_var($row['user_id'] ?? null, FILTER_VALIDATE_INT);
        $providerUserId = $row['provider_user_id'] ?? null;
        if (! $provider instanceof OAuthProvider || $userId === false || ! is_scalar($providerUserId) || (string) $providerUserId === '') {
            return 'invalid';
        }

        $providerUserId = (string) $providerUserId;
        $existing = UserOAuthAccount::query()
            ->where('provider', $provider->value)
            ->where(fn (Builder $query): Builder => $query->where('provider_user_id', $providerUserId)->orWhere('user_id', $userId))
            ->get();

        if ($existing->contains(fn (UserOAuthAccount $account): bool => $account->user_id === $userId && $account->provider_user_id === $providerUserId)) {
            return 'already linked';
        }

        if ($existing->isNotEmpty()) {
            return 'conflict';
        }

        if (! User::query()->whereKey($userId)->exists()) {
            return 'missing user';
        }

        if (! $dryRun) {
            UserOAuthAccount::query()->create([
                'user_id' => $userId,
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
                'provider_username' => $this->nullableString($row['provider_username'] ?? null, 191),
                'provider_email' => $this->nullableString($row['provider_email'] ?? null, 191),
                'provider_avatar' => $this->nullableString($row['provider_avatar'] ?? null, 255),
            ]);
        }

        return 'imported';
    }

    private function nullableString(mixed $value, int $limit): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $limit) : null;
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
