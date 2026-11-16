<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Plugins;

use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Exceptions\Plugins\MarketplaceException;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * Has Wings download a file from an allowed host, then reads it back to check the catalog's
 * hashes. A file that fails the check (or is too large to check) is deleted again.
 */
final readonly class VerifiedDownload
{
    /** Jars and modpacks larger than this are refused. */
    public const int MAX_BYTES = 512 * 1024 * 1024;

    /** Hash algorithms catalogs publish, strongest first. */
    private const array ALGORITHMS = ['sha512', 'sha256', 'sha1', 'md5'];

    /**
     * @param  string  $directory  a clean relative directory ('' for the server root)
     * @param  array<string, string>  $hashes  the catalog's hashes; when $requireHash, one of sha1, sha256 or sha512 must be given
     * @param  string|null  $label  the name shown in errors, when $filename is a temporary one
     *
     * @throws MarketplaceException
     * @throws DaemonConnectionException
     */
    public function download(Server $server, string $url, string $directory, string $filename, array $hashes, bool $requireHash = false, ?string $label = null, int $expectedSize = 0): void
    {
        $label ??= $filename;
        throw_unless(DownloadPolicy::allowedUrl($url), MarketplaceException::class, 'This file is not hosted on an allowed download site, so it was not installed.');

        $expected = [];
        foreach (self::ALGORITHMS as $algorithm) {
            if (isset($hashes[$algorithm])) {
                $expected[$algorithm] = $hashes[$algorithm];
            }
        }

        // MD5 alone is only trusted when nothing better is published.
        if (count($expected) > 1) {
            unset($expected['md5']);
        }

        // An MD5 alone is only accepted together with the size the catalog gives.
        $md5Only = array_diff_key($expected, ['md5' => true]) === [] && isset($expected['md5']);
        throw_if($requireHash && array_diff_key($expected, ['md5' => true]) === [] && ! ($md5Only && $expectedSize > 0), MarketplaceException::class, "The catalog gave no hash for {$label}, so it was not installed.");

        $files = Daemon::server($server)->files();
        $root = '/'.$directory;
        $files->pull($url, $root, ['filename' => $filename, 'foreground' => true, 'timeout' => 600]);

        if ($expected === []) {
            return;
        }

        $path = ($directory === '' ? '' : $directory.'/').$filename;
        try {
            $actual = $files->hashes($path, array_keys($expected), self::MAX_BYTES, bytesRead: $bytesRead);
        } catch (FileSizeTooLargeException) {
            $this->discard($server, $root, $filename);

            throw new MarketplaceException("{$label} is larger than the panel installs, so it was removed.");
        } catch (Throwable $exception) {
            $this->discard($server, $root, $filename);

            throw $exception;
        }

        if ($md5Only && $bytesRead !== $expectedSize) {
            $this->discard($server, $root, $filename);

            throw new MarketplaceException("{$label} did not match the catalog's size, so it was removed.");
        }

        foreach ($expected as $algorithm => $digest) {
            if (! hash_equals($digest, $actual[$algorithm] ?? '')) {
                $this->discard($server, $root, $filename);

                throw new MarketplaceException("{$label} did not match the catalog's {$algorithm} hash, so it was removed.");
            }
        }
    }

    public function discard(Server $server, string $root, string $filename): void
    {
        try {
            Daemon::server($server)->files()->deleteFiles($root, [$filename]);
        } catch (DaemonConnectionException) {
            // Best effort: a leftover .part file is not loaded by the server.
        }
    }
}
