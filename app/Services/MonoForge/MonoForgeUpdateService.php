<?php

declare(strict_types=1);

namespace Pterodactyl\Services\MonoForge;

use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Pterodactyl\Exceptions\Service\MonoForge\UpdateFailedException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;

/**
 * Checks GitHub for new MonoForge builds and installs them in place.
 *
 * The update runs as the panel's own user (www-data) from the scheduler: it downloads the
 * release assets of the configured repository over HTTPS, verifies the sha256, checks and
 * unpacks the tarball into a staging folder, puts the panel into maintenance, backs up the
 * code and the database, copies the new code over the panel (keeping .env and storage/),
 * migrates and brings the panel back up. Any failure after the copy started restores the
 * previous code; a run that dies part way is restored by the next scheduler tick or by
 * "php artisan p:monoforge:recover".
 *
 * @phpstan-type UpdateStatus array{state: string, step: string|null, message: string|null, from: string|null, to: string|null, error: string|null, updated_at: string|null}
 * @phpstan-type UpdateRun array{backup: string|null, down: bool, touched: bool}
 */
class MonoForgeUpdateService
{
    public const string CACHE_KEY = 'monoforge:update:latest';

    /** @var list<string> */
    public const array MODES = ['off', 'notify', 'auto'];

    private const int MAX_TARBALL_BYTES = 300 * 1024 * 1024;

    private const int MAX_CHECKSUM_BYTES = 4096;

    /**
     * Top-level folders whose files are replaced wholesale: files that the new build no
     * longer ships are deleted from them so stale classes or migrations cannot linger.
     *
     * @var list<string>
     */
    private const array MANAGED_DIRECTORIES = ['app', 'bootstrap', 'config', 'database', 'routes', 'vendor'];

    /**
     * Paths (relative to the panel root) that an update never writes to or deletes.
     *
     * @var list<string>
     */
    private const array PRESERVED_PATHS = [
        'storage',
        'extensions',
        'node_modules',
        '.git',
        'bootstrap/cache',
        'public/storage',
        'public/uploads',
        'public/assets/extensions',
    ];

    /**
     * Classes the failure and restore path needs, loaded before the new code is copied in
     * so a half-replaced vendor/ or app/ cannot break the way back.
     *
     * @var list<class-string>
     */
    private const array PRELOAD = [
        UpdateFailedException::class,
        MonoForgeBuild::class,
        MonoForgeRelease::class,
        PendingProcess::class,
        ProcessResult::class,
        ProcessTimedOutException::class,
        \Symfony\Component\Process\Process::class,
        \Symfony\Component\Process\Exception\ProcessTimedOutException::class,
        RuntimeException::class,
        ExecutableFinder::class,
        PhpExecutableFinder::class,
        RecursiveDirectoryIterator::class,
        RecursiveIteratorIterator::class,
    ];

    private readonly string $basePath;

    private readonly string $workPath;

    /** @var resource|null */
    private $lock;

    public function __construct(
        private readonly CacheRepository $cache,
        ?string $basePath = null,
        ?string $workPath = null,
    ) {
        $this->basePath = mb_rtrim($basePath ?? base_path(), '/\\');
        $this->workPath = mb_rtrim($workPath ?? storage_path('app/updates'), '/\\');
    }

    /**
     * The configured update mode: off, notify or auto.
     */
    public function mode(): string
    {
        $mode = config('monoforge.update.mode');

        return in_array($mode, self::MODES, true) ? $mode : 'notify';
    }

    public function repo(): string
    {
        $repo = config()->string('monoforge.update.repo', 'SoloHandCoder/MonoForge-Panel');
        throw_if(preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) !== 1, UpdateFailedException::class, 'The configured MonoForge update repository is not valid.');

        return $repo;
    }

    /**
     * The build that is running, read from version.json at the panel root.
     */
    public function current(): ?MonoForgeBuild
    {
        return $this->readBuild($this->basePath.'/version.json');
    }

    /**
     * The running build's short commit, or the configured app version when it is unknown.
     */
    public function currentVersion(): string
    {
        return $this->current()->version ?? config()->string('app.version');
    }

    /**
     * The latest MonoForge release on GitHub, or null when it cannot be determined.
     * Successful lookups are cached for 30 minutes and failures for 5.
     */
    public function latest(bool $fresh = false): ?MonoForgeRelease
    {
        $cached = $fresh ? null : $this->cache->get(self::CACHE_KEY);
        if (is_array($cached) && array_key_exists('release', $cached)) {
            return MonoForgeRelease::fromArray($cached['release']);
        }

        $release = $this->fetchLatest();
        $minutes = $release instanceof MonoForgeRelease ? config()->integer('monoforge.update.cache_minutes', 30) : 5;
        $this->cache->put(self::CACHE_KEY, ['release' => $release?->toArray()], CarbonImmutable::now()->addMinutes($minutes));

        return $release;
    }

    /**
     * How the latest release relates to the running build:
     * - "available": GitHub says the latest commit is ahead of the running one (or the
     *   running build is unknown, as on a panel without version.json);
     * - "current": the same commit, or one that is not ahead (older, diverged);
     * - "unknown": the latest release or the comparison could not be fetched. Nothing is
     *   installed automatically in that case.
     */
    public function updateCheck(?MonoForgeRelease $latest = null): string
    {
        $latest ??= $this->latest();
        if (! $latest instanceof MonoForgeRelease) {
            return 'unknown';
        }

        $current = $this->current();
        if (! $current instanceof MonoForgeBuild) {
            return 'available';
        }

        if ($current->sameCommitAs($latest->build)) {
            return 'current';
        }

        return match ($this->compare($current, $latest->build)) {
            'ahead' => 'available',
            null => 'unknown',
            default => 'current',
        };
    }

    /**
     * Whether the latest release should replace the running build. Always false when
     * update checks are off or the comparison is unknown.
     */
    public function isUpdateAvailable(?MonoForgeRelease $latest = null): bool
    {
        return $this->mode() !== 'off' && $this->updateCheck($latest) === 'available';
    }

    /**
     * The state of the last requested or applied update.
     *
     * @return UpdateStatus
     */
    public function status(): array
    {
        $status = $this->readStatus();

        // A run that died without finishing (killed, server restart) no longer holds the lock.
        if ($status['state'] === 'running' && $this->acquireLock()) {
            $this->releaseLock();
            $status = $this->writeStatus([...$status, 'state' => 'failed', 'step' => null, 'error' => $this->readRun() !== null
                ? 'The update stopped before it finished. The scheduler restores the previous code within a minute (or run "php artisan p:monoforge:recover").'
                : 'The update stopped before it finished. Check storage/logs for details.']);
        }

        return $status;
    }

    /**
     * Ask the scheduler to install the latest release on its next run (within a minute).
     *
     * @return UpdateStatus
     */
    public function request(): array
    {
        throw_if($this->mode() === 'off', UpdateFailedException::class, 'MonoForge updates are turned off in the panel settings.');

        $status = $this->status();
        throw_if(in_array($status['state'], ['queued', 'running'], true), UpdateFailedException::class, 'An update is already in progress.');

        $latest = $this->latest(true);
        throw_if(! $latest instanceof MonoForgeRelease || $this->updateCheck($latest) !== 'available', UpdateFailedException::class, 'There is no newer MonoForge build to install.');

        return $this->writeStatus([
            'state' => 'queued',
            'step' => null,
            'message' => 'Waiting for the scheduler to start the update.',
            'from' => $this->currentVersion(),
            'to' => $latest->build->version,
            'error' => null,
        ]);
    }

    public function hasPendingRequest(): bool
    {
        return $this->readStatus()['state'] === 'queued';
    }

    /**
     * Drop an update that was queued but has not started (when updates are turned off).
     */
    public function cancelPending(): void
    {
        $status = $this->readStatus();
        if ($status['state'] === 'queued') {
            $this->writeStatus([...$status, 'state' => 'idle', 'step' => null, 'message' => 'The queued update was cancelled.', 'error' => null]);
        }
    }

    /**
     * Install the latest release. Without $force nothing happens unless GitHub says the
     * latest build is ahead of the running one.
     *
     * @return UpdateStatus
     */
    public function apply(bool $force = false): array
    {
        throw_unless($this->acquireLock(), UpdateFailedException::class, 'An update is already in progress.');

        $from = $this->currentVersion();
        /** @var UpdateRun $run */
        $run = ['backup' => null, 'down' => false, 'touched' => false];
        $databaseBackup = null;

        try {
            $this->preload();
            $this->progress('downloading', 'Looking up the latest MonoForge build.', $from, null);
            $release = $this->latest(true);
            throw_unless($release instanceof MonoForgeRelease, UpdateFailedException::class, 'The latest MonoForge release could not be found on GitHub.');

            $to = $release->build->version;
            if (! $force && $this->updateCheck($release) !== 'available') {
                return $this->writeStatus(['state' => 'idle', 'step' => null, 'message' => "There is no newer MonoForge build than {$from} to install.", 'from' => $from, 'to' => $to, 'error' => null]);
            }

            $this->progress('downloading', "Downloading MonoForge build {$to}.", $from, $to);
            $tarball = $this->download($release);

            $this->progress('extracting', 'Unpacking the new build.', $from, $to);
            $staging = $this->extract($tarball, $release);

            $this->progress('maintenance', 'Putting the panel into maintenance mode.', $from, $to);
            // Recorded first: "down" can write the maintenance file and still exit non-zero.
            $run['down'] = true;
            $this->writeRun($run);
            $this->artisan(['down', '--retry=60']);

            $this->progress('backing_up', 'Backing up the current code and database.', $from, $to);
            $stamp = CarbonImmutable::now()->format('Ymd-His');
            $run['backup'] = $this->backupCode($stamp);
            $this->writeRun($run);
            $databaseBackup = $this->backupDatabase($stamp);

            $this->progress('installing', 'Copying the new build into place.', $from, $to);
            $run['touched'] = true;
            $this->writeRun($run);
            $this->syncTree($staging, $this->basePath);
            // The old package, service, config and route caches must not load with the new code.
            $this->clearBootstrapCache();

            $this->progress('migrating', 'Running database migrations.', $from, $to);
            $this->artisan(['migrate', '--force']);

            $this->progress('finishing', 'Rebuilding caches and restarting the queue.', $from, $to);
            $this->artisan(['optimize:clear']);
            // Cached config, routes, events and views make the panel much lighter on the CPU.
            $cacheWarning = $this->optimize();
            $this->artisan(['queue:restart']);
            // The new build ships robots.txt without the sitemap line, which needs the panel address.
            try {
                $this->artisan(['p:monoforge:robots']);
            } catch (Throwable $throwable) {
                Log::warning('MonoForge: robots.txt was not rewritten after the update: '.$throwable->getMessage());
            }
            $this->artisan(['up']);

            $this->clearRun();
            $this->cleanup();
            $this->cache->forget(self::CACHE_KEY);
            Log::info("MonoForge updated from build {$from} to {$to}.");

            $message = "Updated MonoForge from build {$from} to {$to}.".($cacheWarning === null ? '' : ' '.$cacheWarning);

            return $this->writeStatus(['state' => 'success', 'step' => null, 'message' => $message, 'from' => $from, 'to' => $to, 'error' => null]);
        } catch (Throwable $throwable) {
            Log::error('MonoForge update failed: '.$throwable->getMessage(), ['exception' => $throwable]);
            $error = $this->shorten($throwable->getMessage()).$this->rollBack($run);

            if ($databaseBackup !== null) {
                $error .= ' A database backup from before the update is at '.$this->relative($databaseBackup).'.';
            }

            $this->cleanup();
            $this->cache->forget(self::CACHE_KEY);

            return $this->writeStatus(['state' => 'failed', 'step' => null, 'message' => 'The update failed.', 'from' => $from, 'to' => $this->readStatus()['to'], 'error' => $error]);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Cache the panel's config, routes, events and views ("php artisan optimize") in a fresh
     * PHP process. When that fails every cache is cleared again, so the panel keeps working
     * without them. Never throws: returns null on success, otherwise a sentence to show.
     */
    public function optimize(): ?string
    {
        try {
            $this->artisan(['optimize']);

            return null;
        } catch (Throwable $throwable) {
            Log::warning('MonoForge: caching the config, routes, events and views failed: '.$throwable->getMessage());

            try {
                $this->artisan(['optimize:clear']);
            } catch (Throwable $clearFailure) {
                Log::error('MonoForge: clearing the caches after a failed optimize also failed: '.$clearFailure->getMessage());

                return 'The panel caches could not be built or cleared ('.$this->shorten($throwable->getMessage()).'); run "php artisan optimize:clear".';
            }

            return 'The panel caches could not be built ('.$this->shorten($throwable->getMessage()).'), so the panel runs without them.';
        }
    }

    /**
     * Put back the code from before an update that did not finish and leave maintenance.
     * Uses the run that was recorded when there is one, otherwise the newest code backup.
     *
     * @return UpdateStatus
     */
    public function recover(): array
    {
        throw_unless($this->acquireLock(), UpdateFailedException::class, 'An update is in progress; wait for it to finish.');

        try {
            $run = $this->readRun();
            if ($run === null) {
                $backup = $this->newestBackup();
                throw_if($backup === null, UpdateFailedException::class, 'There is no code backup in storage/app/updates to restore.');
                $run = ['backup' => $backup, 'down' => true, 'touched' => true];
            }

            return $this->recoverRun($run);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Recover from an update that died part way (the run is recorded but nobody holds
     * the lock). Returns the new status, or null when there was nothing to recover.
     *
     * @return UpdateStatus|null
     */
    public function recoverIfStale(): ?array
    {
        if (! is_file($this->workPath.'/run.json') || ! $this->acquireLock()) {
            return null;
        }

        try {
            $run = $this->readRun();

            return $run === null ? null : $this->recoverRun($run);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Download the release tarball and its checksum, and verify the checksum. Nothing in
     * the panel is touched before this succeeds.
     */
    public function download(MonoForgeRelease $release): string
    {
        $directory = $this->workPath.'/download';
        $this->deleteTree($directory);
        $this->ensureDirectory($directory);

        $tarball = $directory.'/'.MonoForgeRelease::TARBALL;

        try {
            $checksum = $this->fetchAsset($release->assetUrl(MonoForgeRelease::CHECKSUM), $directory.'/'.MonoForgeRelease::CHECKSUM, self::MAX_CHECKSUM_BYTES);
            $this->fetchAsset($release->assetUrl(MonoForgeRelease::TARBALL), $tarball, self::MAX_TARBALL_BYTES);

            throw_if(preg_match('/^\s*([0-9a-f]{64})\b/i', (string) file_get_contents($checksum), $matches) !== 1, UpdateFailedException::class, 'The release checksum file is not a valid sha256 checksum.');

            $actual = hash_file('sha256', $tarball);
            throw_if($actual === false || ! hash_equals(mb_strtolower($matches[1]), $actual), UpdateFailedException::class, 'The downloaded build does not match its sha256 checksum, so it was not installed.');
        } catch (Throwable $throwable) {
            $this->deleteTree($directory);

            throw $throwable;
        }

        return $tarball;
    }

    private function fetchLatest(): ?MonoForgeRelease
    {
        try {
            $repo = $this->repo();
            $response = $this->githubApi()->get("https://api.github.com/repos/{$repo}/releases/latest");

            return $response->successful() ? MonoForgeRelease::fromGitHub($repo, $response->json()) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * GitHub's comparison of two commits ("ahead", "behind", "identical" or "diverged"),
     * or null when it could not be fetched. Cached like the latest release.
     */
    private function compare(MonoForgeBuild $current, MonoForgeBuild $latest): ?string
    {
        $key = self::CACHE_KEY.':compare:'.$current->commit.'...'.$latest->commit;
        $cached = $this->cache->get($key);
        if (is_array($cached) && array_key_exists('status', $cached)) {
            return is_string($cached['status']) ? $cached['status'] : null;
        }

        try {
            $repo = $this->repo();
            $response = $this->githubApi()->get("https://api.github.com/repos/{$repo}/compare/{$current->commit}...{$latest->commit}");
            $status = $response->successful() ? $response->json('status') : null;
            $status = is_string($status) ? $status : null;
        } catch (Throwable) {
            $status = null;
        }

        $minutes = $status !== null ? config()->integer('monoforge.update.cache_minutes', 30) : 5;
        $this->cache->put($key, ['status' => $status], CarbonImmutable::now()->addMinutes($minutes));

        return $status;
    }

    /**
     * Stream one release asset to $path, following only HTTPS redirects and stopping
     * once it grows past $maxBytes.
     */
    private function fetchAsset(string $url, string $path, int $maxBytes): string
    {
        $response = $this->http()
            ->timeout(600)
            ->connectTimeout(10)
            ->withOptions(['stream' => true, 'allow_redirects' => ['max' => 5, 'protocols' => ['https'], 'strict' => true]])
            ->get($url);

        throw_unless($response->successful(), UpdateFailedException::class, "Downloading {$url} failed with HTTP {$response->status()}.");

        $length = $response->header('Content-Length');
        throw_if(is_numeric($length) && (int) $length > $maxBytes, UpdateFailedException::class, "The release asset at {$url} is larger than expected.");

        $body = $response->toPsrResponse()->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $handle = fopen($path, 'wb');
        throw_if($handle === false, UpdateFailedException::class, 'The update could not write to '.$this->relative($path).'.');

        $written = 0;

        try {
            while (! $body->eof()) {
                $chunk = $body->read(1024 * 1024);
                $written += mb_strlen($chunk, '8bit');
                throw_if($written > $maxBytes, UpdateFailedException::class, "The release asset at {$url} is larger than expected.");

                fwrite($handle, $chunk);
            }
        } finally {
            fclose($handle);
        }

        return $path;
    }

    private function extract(string $tarball, MonoForgeRelease $release): string
    {
        $this->assertSafeArchive($tarball);

        $staging = $this->workPath.'/staging';
        $this->deleteTree($staging);
        $this->ensureDirectory($staging);

        $this->run(['tar', '-xzf', $tarball, '-C', $staging, '--no-same-owner'], 'Unpacking the build failed');

        foreach (['artisan', 'vendor/autoload.php', 'public/index.php'] as $required) {
            throw_unless(is_file($staging.'/'.$required), UpdateFailedException::class, "The downloaded build is missing {$required}, so it was not installed.");
        }

        $build = $this->readBuild($staging.'/version.json');
        throw_if(! $build instanceof MonoForgeBuild || ! $build->sameCommitAs($release->build), UpdateFailedException::class, 'The downloaded build does not identify itself as the release it came from.');

        return $staging;
    }

    /**
     * Refuse archives with anything but regular files and folders (links, devices), with
     * absolute paths or with ".." in a path.
     */
    private function assertSafeArchive(string $tarball): void
    {
        $lines = fn (string $output): array => array_values(array_filter(explode("\n", str_replace("\r", '', $output)), fn (string $line): bool => $line !== ''));
        $names = $lines($this->run(['tar', '-tzf', $tarball], 'Reading the build failed'));
        $details = $lines($this->run(['tar', '-tvzf', $tarball], 'Reading the build failed'));

        throw_if($names === [] || count($names) !== count($details), UpdateFailedException::class, 'The downloaded build could not be read, so it was not installed.');

        foreach ($names as $index => $name) {
            $type = mb_substr($details[$index] ?? '', 0, 1);
            throw_unless($type === '-' || $type === 'd', UpdateFailedException::class, "The downloaded build contains a link or special file ({$name}), so it was not installed.");
            throw_if(str_starts_with($name, '/') || in_array('..', explode('/', $name), true), UpdateFailedException::class, "The downloaded build contains an unsafe path ({$name}), so it was not installed.");
        }
    }

    private function backupCode(string $stamp): string
    {
        $backup = $this->workPath."/backup-{$stamp}.tar.gz";
        $umask = umask(0o077);

        try {
            $result = Process::timeout(900)->run([
                'tar', '-czf', $backup,
                '--exclude=./storage', '--exclude=./.env*', '--exclude=./node_modules', '--exclude=./.git', '--exclude=./extensions',
                '-C', $this->basePath, '.',
            ]);
        } finally {
            umask($umask);
        }

        // GNU tar exits with 1 when a file changed while it was read; the archive is still usable.
        throw_if($result->exitCode() > 1 || ! is_file($backup), UpdateFailedException::class, 'Backing up the current code failed: '.$this->shorten($result->errorOutput()));

        return $backup;
    }

    /**
     * Dump the panel database with mysqldump (or mariadb-dump) when it is installed.
     */
    private function backupDatabase(string $stamp): ?string
    {
        $connection = config()->string('database.default');
        $settings = config("database.connections.{$connection}");
        if (! is_array($settings) || ! in_array($settings['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            return null;
        }

        $finder = new ExecutableFinder;
        $binary = $finder->find('mysqldump') ?? $finder->find('mariadb-dump');
        if ($binary === null) {
            Log::warning('MonoForge update: mysqldump is not installed, so the database was not backed up.');

            return null;
        }

        $dump = $this->workPath."/database-{$stamp}.sql";
        $command = [$binary, '--single-transaction', '--quick', '--no-tablespaces', '--result-file='.$dump];
        $socket = $settings['unix_socket'] ?? '';
        if (is_string($socket) && $socket !== '') {
            $command[] = '--socket='.$socket;
        } else {
            $command[] = '--host='.$this->stringValue($settings['host'] ?? '127.0.0.1');
            $command[] = '--port='.$this->stringValue($settings['port'] ?? '3306');
        }

        $command[] = '--user='.$this->stringValue($settings['username'] ?? '');
        $command[] = $this->stringValue($settings['database'] ?? '');

        $umask = umask(0o077);

        try {
            // The password goes through the environment so it never shows in the process list.
            $result = Process::timeout(1800)->env(['MYSQL_PWD' => $this->stringValue($settings['password'] ?? '')])->run($command);
        } finally {
            umask($umask);
        }

        throw_if($result->failed(), UpdateFailedException::class, 'Backing up the database failed: '.$this->shorten($result->errorOutput()));

        return $dump;
    }

    /**
     * Undo what an unfinished run did. Returns sentences for the error message. When the
     * code cannot be restored the panel stays in maintenance rather than serve half of it.
     *
     * @param  UpdateRun  $run
     */
    private function rollBack(array $run): string
    {
        $message = '';
        if ($run['touched']) {
            if ($run['backup'] === null) {
                return ' No code backup was found, so the panel stays in maintenance mode; restore it by hand.';
            }

            $failure = $this->restore($run['backup']);
            if ($failure !== null) {
                Log::critical('MonoForge update: restoring the code backup failed: '.$failure);

                return ' Restoring the previous code also failed ('.$this->shorten($failure).'), so the panel stays in maintenance mode. Run "php artisan p:monoforge:recover" or restore '.$this->relative($run['backup']).' by hand.';
            }

            $message = ' The previous code was restored from '.$this->relative($run['backup']).'.';
        }

        if ($run['down']) {
            $message .= $this->bringUp();
        }

        $this->clearRun();

        return $message;
    }

    /**
     * @param  UpdateRun  $run
     * @return UpdateStatus
     */
    private function recoverRun(array $run): array
    {
        $this->preload();
        $previous = $this->readStatus();
        $this->progress('restoring', 'Restoring the previous code.', null, null);
        $message = $this->rollBack($run);
        // rollBack keeps the run only when restoring failed; that is reported, not retried forever.
        $failed = $this->readRun() !== null;
        $this->clearRun();

        return $this->writeStatus([
            'state' => 'failed',
            'step' => null,
            'message' => $failed ? 'Recovering from the unfinished update failed.' : 'Recovered from an unfinished update.',
            'from' => $previous['from'],
            'to' => $previous['to'],
            'error' => 'The update did not finish.'.$message,
        ]);
    }

    /**
     * Put the backed up code back in place. Returns why it failed, or null on success.
     */
    private function restore(string $backup): ?string
    {
        try {
            throw_unless(is_file($backup), UpdateFailedException::class, 'The backup '.$this->relative($backup).' does not exist.');
            $restore = $this->workPath.'/restore';
            $this->deleteTree($restore);
            $this->ensureDirectory($restore);
            $this->run(['tar', '-xzf', $backup, '-C', $restore, '--no-same-owner'], 'Unpacking the code backup failed');
            $this->syncTree($restore, $this->basePath);
            if (! is_file($restore.'/version.json') && is_file($this->basePath.'/version.json')) {
                @unlink($this->basePath.'/version.json');
            }

            $this->deleteTree($restore);
            $this->clearBootstrapCache();

            try {
                $this->artisan(['optimize:clear']);
                $this->artisan(['queue:restart']);
            } catch (Throwable) {
            }

            return null;
        } catch (Throwable $throwable) {
            return $throwable->getMessage();
        }
    }

    private function bringUp(): string
    {
        try {
            $this->artisan(['up']);

            return '';
        } catch (Throwable) {
            return ' The panel could not leave maintenance mode; run "php artisan up".';
        }
    }

    /**
     * Copy every file from $source over $target, except preserved paths and symbolic links,
     * then delete files from the managed folders that $source does not have.
     */
    private function syncTree(string $source, string $target): void
    {
        $kept = [];
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $relative = $this->relativeTo($source, $item->getPathname());
            if ($item->isLink() || $this->isPreserved($relative)) {
                continue;
            }

            $destination = $target.'/'.$relative;
            if ($item->isDir()) {
                $this->ensureDirectory($destination);

                continue;
            }

            $temporary = $destination.'.monoforge-new';
            if (! @copy($item->getPathname(), $temporary) || ! @rename($temporary, $destination)) {
                @unlink($temporary);

                throw new UpdateFailedException("Could not write {$relative}; check that the panel user owns the panel folder.");
            }

            @chmod($destination, $item->getPerms() & 0o777);
            $kept[$relative] = true;
        }

        foreach (self::MANAGED_DIRECTORIES as $directory) {
            if (! is_dir($source.'/'.$directory) || ! is_dir($target.'/'.$directory)) {
                continue;
            }

            $existing = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target.'/'.$directory, FilesystemIterator::SKIP_DOTS));

            /** @var SplFileInfo $item */
            foreach ($existing as $item) {
                $relative = $this->relativeTo($target, $item->getPathname());
                if (! isset($kept[$relative]) && ! $item->isLink() && ! $this->isPreserved($relative)) {
                    @unlink($item->getPathname());
                }
            }
        }
    }

    /**
     * Delete Laravel's compiled package, service, config, event and route caches.
     */
    private function clearBootstrapCache(): void
    {
        $cache = $this->basePath.'/bootstrap/cache';
        foreach (['packages.php', 'services.php', 'config.php', 'events.php'] as $file) {
            @unlink($cache.'/'.$file);
        }

        foreach (glob($cache.'/routes-*.php') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function isPreserved(string $relative): bool
    {
        if (! str_contains($relative, '/') && str_starts_with($relative, '.env')) {
            return true;
        }

        foreach (self::PRESERVED_PATHS as $path) {
            if ($relative === $path || str_starts_with($relative, $path.'/')) {
                return true;
            }
        }

        return false;
    }

    private function preload(): void
    {
        foreach (self::PRELOAD as $class) {
            class_exists($class);
        }

        // Loads the logger and its handlers too.
        Log::info('MonoForge update: preparing.');
    }

    /**
     * Run an artisan command of the panel in its own PHP process, so it loads the code
     * that is on disk now rather than the code this process started with.
     *
     * @param  list<string>  $arguments
     */
    private function artisan(array $arguments): void
    {
        $php = (new PhpExecutableFinder)->find(false);
        $this->run([is_string($php) && $php !== '' ? $php : 'php', $this->basePath.'/artisan', ...$arguments, '--no-interaction'], 'php artisan '.$arguments[0].' failed');
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, string $failure): string
    {
        $result = Process::path($this->basePath)->timeout(900)->run($command);
        if ($result->failed()) {
            $output = mb_trim($result->errorOutput()) !== '' ? $result->errorOutput() : $result->output();

            throw new UpdateFailedException($failure.': '.$this->shorten($output));
        }

        return $result->output();
    }

    private function progress(string $step, string $message, ?string $from, ?string $to): void
    {
        $previous = $this->readStatus();
        $this->writeStatus([
            'state' => 'running',
            'step' => $step,
            'message' => $message,
            'from' => $from ?? $previous['from'],
            'to' => $to ?? $previous['to'],
            'error' => null,
        ]);
    }

    /**
     * @return UpdateStatus
     */
    private function readStatus(): array
    {
        $data = $this->readJson($this->workPath.'/status.json') ?? [];
        $string = fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;

        return [
            'state' => $string('state') ?? 'idle',
            'step' => $string('step'),
            'message' => $string('message'),
            'from' => $string('from'),
            'to' => $string('to'),
            'error' => $string('error'),
            'updated_at' => $string('updated_at'),
        ];
    }

    /**
     * @param  array{state: string, step: string|null, message: string|null, from: string|null, to: string|null, error: string|null, updated_at?: string|null}  $status
     * @return UpdateStatus
     */
    private function writeStatus(array $status): array
    {
        $status['updated_at'] = CarbonImmutable::now()->toIso8601ZuluString();
        $this->ensureDirectory($this->workPath);
        file_put_contents($this->workPath.'/status.json', json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX);

        return $status;
    }

    /**
     * The run in progress (or abandoned): its code backup, whether the panel was put
     * into maintenance and whether the new code started being copied.
     *
     * @return UpdateRun|null
     */
    private function readRun(): ?array
    {
        $data = $this->readJson($this->workPath.'/run.json');
        if ($data === null) {
            return null;
        }

        return [
            'backup' => is_string($data['backup'] ?? null) ? $data['backup'] : null,
            'down' => ($data['down'] ?? false) === true,
            'touched' => ($data['touched'] ?? false) === true,
        ];
    }

    /**
     * @param  UpdateRun  $run
     */
    private function writeRun(array $run): void
    {
        file_put_contents($this->workPath.'/run.json', json_encode($run, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function clearRun(): void
    {
        @unlink($this->workPath.'/run.json');
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    private function newestBackup(): ?string
    {
        $files = glob($this->workPath.'/backup-*.tar.gz') ?: [];
        rsort($files);

        return $files[0] ?? null;
    }

    private function acquireLock(): bool
    {
        $this->ensureDirectory($this->workPath);
        $handle = fopen($this->workPath.'/update.lock', 'c');
        if ($handle === false) {
            return false;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->lock = $handle;

        return true;
    }

    private function releaseLock(): void
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }

        $this->lock = null;
    }

    /**
     * Remove the download and staging folders and keep only the newest backups.
     */
    private function cleanup(): void
    {
        $this->deleteTree($this->workPath.'/download');
        $this->deleteTree($this->workPath.'/staging');

        $keep = max(1, config()->integer('monoforge.update.keep_backups', 3));
        foreach (['backup-*.tar.gz', 'database-*.sql'] as $pattern) {
            $files = glob($this->workPath.'/'.$pattern) ?: [];
            rsort($files);
            foreach (array_slice($files, $keep) as $file) {
                @unlink($file);
            }
        }
    }

    private function readBuild(string $path): ?MonoForgeBuild
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            return MonoForgeBuild::fromArray(json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return null;
        }
    }

    private function deleteTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0o755, true) && ! is_dir($path)) {
            throw new UpdateFailedException('Could not create '.$this->relative($path).'.');
        }
    }

    private function relativeTo(string $root, string $path): string
    {
        return str_replace('\\', '/', mb_substr($path, mb_strlen($root) + 1));
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, $this->basePath.'/') ? $this->relativeTo($this->basePath, $path) : $path;
    }

    private function shorten(string $message): string
    {
        $message = mb_trim($message);

        return mb_strlen($message) > 600 ? mb_substr($message, 0, 600).'…' : $message;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function http(): PendingRequest
    {
        return Http::withUserAgent('MonoForge-Panel-Updater');
    }

    private function githubApi(): PendingRequest
    {
        return $this->http()
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(5)
            ->connectTimeout(3);
    }
}
