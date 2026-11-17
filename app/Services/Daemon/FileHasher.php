<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Daemon;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Enum\JwtScope;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\FileSizeTooLargeException;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Nodes\NodeJwt;

/**
 * Hashes a file on a server without holding it in memory. Wings' contents endpoint is tried
 * first; a Wings that refuses to show an archive there (wings-rs answers "file is an archive,
 * cannot view contents" for every .jar and .zip) is asked for the file through a short-lived
 * signed download link instead, the one the file manager's Download button uses.
 */
final readonly class FileHasher
{
    /** How long the signed download link is valid. */
    private const int LINK_MINUTES = 10;

    public function __construct(private NodeJwt $jwt) {}

    /** Whether Wings refused the contents endpoint because the file is an archive. */
    public static function refusedArchive(DaemonConnectionException $exception): bool
    {
        $text = mb_strtolower($exception->getMessage().' '.$exception->getPrevious()->getMessage());

        return str_contains($text, 'is an archive') || str_contains($text, 'cannot view contents');
    }

    /**
     * @param  string  $path  the file, relative to the server root
     * @param  list<string>  $algorithms  hash_algos() names, such as sha1 and sha512
     * @return array<string, string> algorithm => lowercase hex digest
     *
     * @param-out int $bytesRead
     *
     * @throws FileSizeTooLargeException when the file is larger than $notLargerThan bytes
     * @throws DaemonConnectionException
     */
    public function hash(Server $server, string $path, array $algorithms, int $notLargerThan, int $timeout = 300, ?int &$bytesRead = null): array
    {
        try {
            return Daemon::server($server)->files()->hashes($path, $algorithms, $notLargerThan, $timeout, $bytesRead);
        } catch (DaemonConnectionException $daemonConnectionException) {
            throw_unless(self::refusedArchive($daemonConnectionException), $daemonConnectionException);
        }

        return $this->viaDownload($server, $path, $algorithms, $notLargerThan, $timeout, $bytesRead);
    }

    /**
     * @param  list<string>  $algorithms
     * @return array<string, string>
     *
     * @param-out int $bytesRead
     *
     * @throws FileSizeTooLargeException
     * @throws DaemonConnectionException
     */
    private function viaDownload(Server $server, string $path, array $algorithms, int $notLargerThan, int $timeout, ?int &$bytesRead): array
    {
        $node = $server->node;
        $file = '/'.mb_ltrim($path, '/');
        $token = $this->jwt->issue(
            $node,
            JwtScope::FileDownload,
            identifiedBy: 'hash'.$server->uuid.$file,
            expiresAt: CarbonImmutable::now()->addMinutes(self::LINK_MINUTES),
            claims: ['file_path' => $file, 'server_uuid' => $server->uuid],
        );

        try {
            $response = Http::withOptions(['stream' => true, 'verify' => App::isProduction()])
                ->timeout($timeout)
                ->connectTimeout(config()->integer('pterodactyl.guzzle.connect_timeout', 5))
                ->get($node->getConnectionAddress().'/download/file', ['token' => $token->toString()])
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw new DaemonConnectionException($exception);
        }

        return FilesClient::hashStream($response->toPsrResponse()->getBody(), $algorithms, $notLargerThan, $bytesRead);
    }
}
