<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\Migration;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Database\Connection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Setting;
use Pterodactyl\Services\Migration\M12LabsSettingsImporter;
use RuntimeException;
use Throwable;

/**
 * Copies login provider, captcha and email settings from an m12labs panel.
 * Connection options and the SOURCE_DB_PASSWORD / SOURCE_APP_KEY variables work
 * the same as for p:migrate:m12labs. Secret values are never printed.
 */
#[Description('Import Discord/Google login, Turnstile captcha, Resend and sender settings from an m12labs panel database.')]
#[Signature('p:migrate:m12labs-settings
    {--host=127.0.0.1 : m12labs database host}
    {--port=3306 : m12labs database port}
    {--database= : m12labs database name}
    {--username= : m12labs database username}
    {--password= : m12labs database password (prefer the SOURCE_DB_PASSWORD environment variable)}
    {--source-key= : m12labs APP_KEY from its .env (prefer the SOURCE_APP_KEY environment variable)}
    {--skip-undecryptable : Import the rest when some values do not decrypt with the m12labs APP_KEY (normally the run is aborted)}
    {--dry-run : List the settings that would be set (never their values) and write nothing}
    {--assume-yes : Answer the confirmation prompt with yes, for unattended runs}')]
class ImportM12LabsSettingsCommand extends Command
{
    private const string PASSWORD_ENV = 'SOURCE_DB_PASSWORD';

    private const string APP_KEY_ENV = 'SOURCE_APP_KEY';

    private const string CONNECTION = 'm12labs_settings_source';

    public function handle(EncrypterContract $encrypter): int
    {
        try {
            $importer = new M12LabsSettingsImporter($this->sourceEncrypter(), $encrypter);
            $source = $this->connectToSource();
            $rows = $this->readRows($source);
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());

            return self::FAILURE;
        }

        $settings = $importer->map($rows);

        if (isset($settings['app:name']) && Setting::fetch('settings::app:name') !== null) {
            unset($settings['app:name']);
            $this->line('  · Keeping the panel name already set in MonoForge.');
        }

        $undecryptable = $importer->undecryptable();
        if ($undecryptable !== []) {
            $skip = (bool) $this->option('skip-undecryptable');
            foreach ($undecryptable as $key) {
                $this->warn("  ! {$key} could not be decrypted with the m12labs APP_KEY".($skip ? ' and was skipped.' : '.'));
            }

            if (! $skip) {
                $this->error('Is SOURCE_APP_KEY the APP_KEY of that m12labs panel? Nothing was written. Pass --skip-undecryptable to import the rest anyway.');

                return self::FAILURE;
            }
        }

        if ($settings === []) {
            $this->info('No m12labs settings to import.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->table(
            ['MonoForge setting', 'From m12labs', 'Stored'],
            array_map(
                fn (string $key, array $setting): array => [$key, $setting['from'], M12LabsSettingsImporter::isSecret($key) ? 'encrypted' : 'plain'],
                array_keys($settings),
                $settings,
            ),
        );

        if ($importer->decisions() !== []) {
            $this->table(
                ['Decision', 'Result', 'Based on'],
                array_map(fn (array $decision): array => [$decision['setting'], $decision['decision'], $decision['because']], $importer->decisions()),
            );
        }

        if ($dryRun) {
            $this->info('Dry run: '.count($settings).' setting(s) would be set. Nothing was written.');

            return self::SUCCESS;
        }

        if (! $this->confirmRun()) {
            $this->warn('Aborted. Nothing was written.');

            return self::FAILURE;
        }

        try {
            DB::transaction(fn () => $importer->apply($settings));
        } catch (Throwable $throwable) {
            $this->error('Writing the settings failed and was rolled back: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->info('Imported '.count($settings).' setting(s).');
        $this->line('Restart the queue workers (php artisan queue:restart) so they pick up the new mail settings.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string|null>
     */
    private function readRows(Connection $source): array
    {
        try {
            /** @var array<string, string|null> $rows */
            $rows = $source->table('settings')
                ->whereIn('key', M12LabsSettingsImporter::SOURCE_KEYS)
                ->pluck('value', 'key')
                ->all();
        } catch (Throwable $throwable) {
            throw new RuntimeException('Could not read the m12labs settings table: '.$throwable->getMessage(), 0, $throwable);
        }

        return $rows;
    }

    private function confirmRun(): bool
    {
        if ($this->option('assume-yes')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Refusing to write settings unattended without --assume-yes.');

            return false;
        }

        return $this->confirm('Overwrite these MonoForge settings?', false);
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
            throw new RuntimeException('Could not connect to the m12labs database: '.$throwable->getMessage(), 0, $throwable);
        }

        return $connection;
    }

    private function sourceEncrypter(): Encrypter
    {
        $key = $this->secretOption('source-key', self::APP_KEY_ENV, 'm12labs APP_KEY (from its .env file)');
        throw_unless($key, RuntimeException::class, 'The m12labs APP_KEY is required: the login, captcha and Resend secrets are encrypted with it.');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(mb_substr($key, 7), true);
        }

        // The decoded key is raw bytes, so count bytes ('8bit'), not characters.
        $cipher = match (mb_strlen($key, '8bit')) {
            32 => 'AES-256-CBC',
            16 => 'AES-128-CBC',
            default => throw new RuntimeException('That is not a valid Laravel APP_KEY. Expected a 16 or 32 byte key, usually written as base64:…'),
        };

        return new Encrypter($key, $cipher);
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
