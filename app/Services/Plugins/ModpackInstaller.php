<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Plugins;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use JsonException;
use Pterodactyl\Contracts\Backups\InitiatesBackups;
use Pterodactyl\Data\Plugins\MarketplaceFile;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Exceptions\Plugins\MarketplaceException;
use Pterodactyl\Exceptions\Service\Backup\TooManyBackupsException;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * Installs a Modrinth modpack (.mrpack, https://support.modrinth.com/en/articles/8802351):
 *
 *  1. backs the server up first (see backupStep(): the job waits for it without sleeping),
 *     or goes on without one only when the user confirmed that;
 *  2. has Wings download the .mrpack into a staging folder, checks its hashes, extracts it;
 *  3. reads modrinth.index.json and checks every file before anything is downloaded: a
 *     relative path inside the server root, an allowed download host, a sha1 or sha512;
 *  4. downloads each file the server needs (env.server is not "unsupported") into staging
 *     and checks its hashes;
 *  5. only then moves the files into place, then overrides/ and server-overrides/ on top.
 *
 * Until step 5 nothing outside the staging folder changes, so a failed install leaves the
 * server as it was. Step 5 first checks every target and refuses, before changing anything,
 * when a target and what is there differ in type (file or folder), or when the pack would
 * replace anything in a world folder or a plugin's data folder. Folders are never deleted.
 * The staging folder is removed at the end either way.
 */
final readonly class ModpackInstaller
{
    public const string STAGING = '.monoforge-modpack';

    /** Most files an index may list. */
    public const int MAX_FILES = 2000;

    /** How long to wait for the backup, in seconds. */
    public const int BACKUP_WAIT = 600;

    public const string NO_BACKUP_MESSAGE = 'This server has no free backup slot, so the modpack was not installed. Free a backup slot, or confirm installing without a backup.';

    /** Most override files and folders walked. */
    private const int MAX_OVERRIDES = 10000;

    private const int MAX_INDEX_BYTES = 10 * 1024 * 1024;

    /** Files moved per Wings rename or delete call. */
    private const int CHUNK = 100;

    public function __construct(private VerifiedDownload $downloads) {}

    /** Whether the server has a free backup slot for the backup before an install. */
    public static function canBackup(Server $server): bool
    {
        $limit = (int) $server->backup_limit;

        return $limit > 0 && $server->backups()->nonFailed()->count() < $limit;
    }

    /**
     * Whether a pack may not replace the path: anything in a world folder (world, world_nether,
     * world_the_end, ...) or in a plugin's data folder (plugins/<name>/...).
     */
    public static function protectedPath(string $path): bool
    {
        $parts = explode('/', mb_strtolower($path));

        return str_starts_with($parts[0], 'world') || ($parts[0] === 'plugins' && count($parts) >= 3);
    }

    /**
     * @throws MarketplaceException
     * @throws Throwable
     */
    public function install(Server $server, MarketplaceFile $pack): void
    {
        $files = Daemon::server($server)->files();

        ModpackProgress::update($server, ['step' => 'download']);
        $this->removeStaging($server);
        $files->createDirectory(self::STAGING, '/');

        try {
            $this->downloads->download($server, $pack->url, self::STAGING, 'pack.zip', $pack->hashes, requireHash: true, label: $pack->filename);

            ModpackProgress::update($server, ['step' => 'extract']);
            $files->decompressFile('/'.self::STAGING, 'pack.zip');

            $entries = $this->readIndex($server);
            ModpackProgress::update($server, ['step' => 'files', 'files_total' => count($entries)]);

            if ($entries !== []) {
                $files->createDirectory('files', '/'.self::STAGING);
            }

            foreach ($entries as $index => $entry) {
                $this->downloads->download($server, $entry['url'], self::STAGING.'/files', $index.'.part', $entry['hashes'], requireHash: true, label: $entry['path']);
                ModpackProgress::update($server, ['files_done' => $index + 1]);
            }

            ModpackProgress::update($server, ['step' => 'overrides']);
            // Keyed by target, so later sources win: server-overrides over overrides over files.
            $sources = [];
            foreach ($entries as $index => $entry) {
                $sources[$entry['path']] = self::STAGING.'/files/'.$index.'.part';
            }

            foreach (['overrides', 'server-overrides'] as $folder) {
                foreach ($this->walk($server, self::STAGING.'/'.$folder) as $relative) {
                    $sources[$relative] = self::STAGING.'/'.$folder.'/'.$relative;
                }
            }

            ModpackProgress::update($server, ['step' => 'install']);
            $this->apply($server, $sources);
        } finally {
            ModpackProgress::update($server, ['step' => 'cleanup']);
            $this->removeStaging($server);
        }
    }

    /**
     * The backup before the install, one step at a time so the job never sleeps: the first call
     * starts it, later calls check it. True when the install may go on (the backup completed, or
     * the user confirmed going on without one), false while it is still running.
     *
     * @throws MarketplaceException when there is no backup and no confirmation, or it failed or took too long
     */
    public function backupStep(Server $server, bool $confirmedWithoutBackup): bool
    {
        $key = 'plugins:modpack-backup:'.$server->uuid;
        /** @var array{uuid: string, started_at: int}|null $pending */
        $pending = Cache::get($key);

        if (! is_array($pending)) {
            $backup = null;
            if (self::canBackup($server)) {
                try {
                    $backup = App::make(InitiatesBackups::class)->initiate($server, 'Before modpack install');
                } catch (TooManyBackupsException) {
                    $backup = null;
                }
            }

            if ($backup === null) {
                throw_unless($confirmedWithoutBackup, MarketplaceException::class, self::NO_BACKUP_MESSAGE);
                ModpackProgress::update($server, ['backup' => 'skipped']);

                return true;
            }

            ModpackProgress::update($server, ['step' => 'backup']);
            Cache::put($key, ['uuid' => $backup->uuid, 'started_at' => now()->getTimestamp()], self::BACKUP_WAIT * 2);

            return false;
        }

        $backup = $server->backups()->where('uuid', $pending['uuid'])->first();
        if ($backup !== null && $backup->completed_at !== null) {
            Cache::forget($key);
            throw_unless($backup->is_successful, MarketplaceException::class, 'The backup before the install failed, so the modpack was not installed.');
            ModpackProgress::update($server, ['backup' => 'created']);

            return true;
        }

        if ($backup === null || now()->getTimestamp() - $pending['started_at'] >= self::BACKUP_WAIT) {
            Cache::forget($key);

            throw new MarketplaceException('The backup before the install took too long, so the modpack was not installed.');
        }

        return false;
    }

    /**
     * The files of modrinth.index.json the server needs, each checked.
     *
     * @return list<array{path: string, url: string, hashes: array<string, string>}>
     *
     * @throws MarketplaceException
     */
    private function readIndex(Server $server): array
    {
        try {
            $content = Daemon::server($server)->files()->getContent(self::STAGING.'/modrinth.index.json', self::MAX_INDEX_BYTES);
            $index = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (DaemonConnectionException|FileSizeTooLargeException|JsonException) {
            throw new MarketplaceException('The modpack has no readable modrinth.index.json.');
        }

        throw_if(! is_array($index) || Payload::int($index, 'formatVersion') !== 1 || Payload::string($index, 'game') !== 'minecraft', MarketplaceException::class, 'The modpack is not a Minecraft modpack in a format the panel knows.');

        $listed = Payload::objects($index, 'files');
        throw_if(count($listed) > self::MAX_FILES, MarketplaceException::class, 'The modpack lists more files than the panel installs.');

        $entries = [];
        $paths = [];
        foreach ($listed as $file) {
            if (Payload::string(Payload::array($file, 'env'), 'server') === 'unsupported') {
                continue;
            }

            $raw = Payload::string($file, 'path');
            $path = DownloadPolicy::relativePath($raw);
            if ($path === null || str_starts_with(mb_strtolower($path), self::STAGING)) {
                throw new MarketplaceException("The modpack wants to write outside the server folder ({$this->label($raw)}), so it was not installed.");
            }

            if (isset($paths[$path])) {
                continue;
            }

            $url = null;
            foreach (Payload::strings($file, 'downloads') as $candidate) {
                if (DownloadPolicy::allowedUrl($candidate)) {
                    $url = $candidate;
                    break;
                }
            }

            if ($url === null) {
                throw new MarketplaceException("{$this->label($path)} is not hosted on an allowed download site, so the modpack was not installed.");
            }

            $hashes = [];
            foreach (['sha1', 'sha512'] as $algorithm) {
                $value = mb_strtolower(Payload::string(Payload::array($file, 'hashes'), $algorithm));
                if (preg_match('/^[0-9a-f]+$/', $value) === 1) {
                    $hashes[$algorithm] = $value;
                }
            }

            if ($hashes === []) {
                throw new MarketplaceException("The modpack gives no hash for {$this->label($path)}, so it was not installed.");
            }

            $paths[$path] = true;
            $entries[] = ['path' => $path, 'url' => $url, 'hashes' => $hashes];
        }

        return $entries;
    }

    /**
     * Every file under a staging folder, as paths relative to it. A folder that is missing
     * has none; a name that is not a safe path stops the install.
     *
     * @return list<string>
     *
     * @throws MarketplaceException
     */
    private function walk(Server $server, string $root): array
    {
        $found = [];
        $queue = [''];
        $seen = 0;

        while ($queue !== []) {
            $relative = array_shift($queue);
            try {
                $entries = Daemon::server($server)->files()->getDirectory('/'.$root.($relative === '' ? '' : '/'.$relative));
            } catch (DaemonConnectionException $exception) {
                if ($relative === '' && $exception->getStatusCode() === 404) {
                    return [];
                }

                throw $exception;
            }

            foreach ($entries as $entry) {
                throw_if(++$seen > self::MAX_OVERRIDES, MarketplaceException::class, 'The modpack has more override files than the panel installs.');

                $name = $entry['name'] ?? '';
                $path = DownloadPolicy::relativePath(($relative === '' ? '' : $relative.'/').$name);
                if ($path === null || ($entry['symlink'] ?? false) === true) {
                    throw new MarketplaceException("The modpack has an override the panel does not install ({$this->label($name)}).");
                }

                if (($entry['file'] ?? false) === true) {
                    $found[] = $path;
                } elseif (mb_substr_count($path, '/') < 32) {
                    $queue[] = $path;
                }
            }
        }

        return $found;
    }

    /**
     * Moves staged files to their targets, replacing what is there.
     *
     * @param  array<string, string>  $sources  target path => staged path
     *
     * @throws DaemonConnectionException
     */
    private function apply(Server $server, array $sources): void
    {
        $files = Daemon::server($server)->files();
        // A target such as "123" becomes an integer key.
        $targets = array_map(strval(...), array_keys($sources));

        // The folders the targets go into, parents first, with what each already holds.
        $folders = [];
        foreach ($targets as $target) {
            $parts = explode('/', $target);
            array_pop($parts);
            $counter = count($parts);
            for ($depth = 1; $depth <= $counter; $depth++) {
                $folders[implode('/', array_slice($parts, 0, $depth))] = true;
            }
        }

        $folders = array_keys($folders);
        usort($folders, fn (string $a, string $b): int => mb_substr_count($a, '/') <=> mb_substr_count($b, '/') ?: strcmp($a, $b));

        // Check everything first: nothing is changed until every target is known to be safe.
        /** @var array<string, array<string, bool>|null> $contents folder => name => is a file (null: to be created) */
        $contents = ['' => $this->entries($server, '')];
        $create = [];
        foreach ($folders as $folder) {
            $parent = str_contains($folder, '/') ? mb_substr($folder, 0, (int) mb_strrpos($folder, '/')) : '';
            $name = basename($folder);
            $around = $contents[$parent] ?? null;
            if ($around !== null && array_key_exists($name, $around)) {
                throw_if($around[$name], MarketplaceException::class, "The modpack needs a folder where the server has a file ({$this->label($folder)}), so nothing was changed.");
                $contents[$folder] = $this->entries($server, $folder);
            } else {
                $create[] = [$name, $parent];
                $contents[$folder] = null;
            }
        }

        $replace = [];
        foreach ($targets as $target) {
            $parent = str_contains($target, '/') ? mb_substr($target, 0, (int) mb_strrpos($target, '/')) : '';
            $around = $contents[$parent] ?? null;
            $name = basename($target);
            if ($around === null || ! array_key_exists($name, $around)) {
                continue;
            }

            throw_unless($around[$name], MarketplaceException::class, "The modpack would put a file where the server has a folder ({$this->label($target)}), so nothing was changed.");
            throw_if(self::protectedPath($target), MarketplaceException::class, "The modpack would replace {$this->label($target)}, which may hold a world or plugin data, so nothing was changed.");
            $replace[] = $target;
        }

        foreach ($create as [$name, $parent]) {
            $files->createDirectory($name, '/'.$parent);
        }

        // Only files are ever deleted (each checked above), and only to be replaced.
        foreach (array_chunk($replace, self::CHUNK) as $chunk) {
            $files->deleteFiles('/', $chunk);
        }

        $renames = [];
        foreach ($sources as $to => $from) {
            $renames[] = ['from' => $from, 'to' => (string) $to];
        }

        foreach (array_chunk($renames, self::CHUNK) as $chunk) {
            $files->renameFiles('/', $chunk);
        }
    }

    /**
     * The folder's entries: name => whether it is a file.
     *
     * @return array<string, bool>
     *
     * @throws DaemonConnectionException
     */
    private function entries(Server $server, string $folder): array
    {
        try {
            $entries = Daemon::server($server)->files()->getDirectory('/'.$folder);
        } catch (DaemonConnectionException $daemonConnectionException) {
            if ($daemonConnectionException->getStatusCode() === 404) {
                return [];
            }

            throw $daemonConnectionException;
        }

        $found = [];
        foreach ($entries as $entry) {
            $found[(string) ($entry['name'] ?? '')] = ($entry['file'] ?? false) === true;
        }

        return $found;
    }

    private function removeStaging(Server $server): void
    {
        try {
            Daemon::server($server)->files()->deleteFiles('/', [self::STAGING]);
        } catch (DaemonConnectionException) {
            // Nothing staged yet, or Wings is away: the next install clears it.
        }
    }

    /** A path from the pack, shortened and stripped for an error message. */
    private function label(string $path): string
    {
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '', $path) ?? '', 0, 120);
    }
}
