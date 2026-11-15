<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Plugins;

use Illuminate\Support\Str;
use Pterodactyl\Data\Plugins\MarketplaceFile;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Plugins\MarketplaceException;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * Installs, replaces and removes plugin and mod jars. A jar is downloaded under a temporary
 * ".part" name (which no server loads), checked against the catalog's hashes, and only then
 * renamed into place, so a failed or tampered download never reaches the server.
 */
final readonly class PluginInstaller
{
    public function __construct(private VerifiedDownload $downloads) {}

    /**
     * @param  string  $directory  plugins or mods
     * @param  string|null  $replaces  an installed jar in the directory to remove once the new one is in place
     * @return string the installed jar's file name
     *
     * @throws MarketplaceException
     * @throws DaemonConnectionException
     */
    public function install(Server $server, MarketplaceFile $file, string $directory, ?string $replaces = null): string
    {
        $name = DownloadPolicy::jarName($file->filename);
        throw_if($name === null || DownloadPolicy::relativePath($directory) === null, MarketplaceException::class, 'The catalog gave this file a name the panel does not install.');

        $files = Daemon::server($server)->files();
        $root = '/'.$directory;
        $existing = $this->jarNames($server, $directory);

        $temporary = '.monoforge-'.Str::lower(Str::random(16)).'.part';
        $this->downloads->download($server, $file->url, $directory, $temporary, $file->hashes, label: $name);

        try {
            if (in_array($name, $existing, true)) {
                $files->deleteFiles($root, [$name]);
            }

            $files->renameFiles($root, [['from' => $temporary, 'to' => $name]]);
        } catch (Throwable $throwable) {
            $this->downloads->discard($server, $root, $temporary);

            throw $throwable;
        }

        if ($replaces !== null && $replaces !== $name && in_array($replaces, $existing, true)) {
            $files->deleteFiles($root, [$replaces]);
        }

        return $name;
    }

    /** @throws DaemonConnectionException */
    public function remove(Server $server, string $directory, string $name): void
    {
        Daemon::server($server)->files()->deleteFiles('/'.$directory, [$name]);
    }

    /**
     * The jars in the directory, or none when it does not exist yet.
     *
     * @return list<string>
     *
     * @throws DaemonConnectionException
     */
    public function jarNames(Server $server, string $directory): array
    {
        return array_column($this->jars($server, $directory), 'name');
    }

    /**
     * @return list<array{name: string, size: int, modified: string}>
     *
     * @throws DaemonConnectionException
     */
    public function jars(Server $server, string $directory): array
    {
        try {
            $entries = Daemon::server($server)->files()->getDirectory('/'.$directory);
        } catch (DaemonConnectionException $daemonConnectionException) {
            if ($daemonConnectionException->getStatusCode() === 404) {
                return [];
            }

            throw $daemonConnectionException;
        }

        $jars = [];
        foreach ($entries as $entry) {
            $name = $entry['name'] ?? '';
            if (($entry['file'] ?? false) === true && DownloadPolicy::jarName($name) !== null) {
                $jars[] = ['name' => $name, 'size' => $entry['size'] ?? 0, 'modified' => $entry['modified'] ?? ''];
            }
        }

        usort($jars, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $jars;
    }
}
