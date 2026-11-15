<?php

declare(strict_types=1);

namespace Pterodactyl\Data;

use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Http\Server\ServerStateConflictException;
use Pterodactyl\Models\Server;

/**
 * Why a server's live state could not be read: the state it is in, which the single
 * resources endpoint answers with a 409, or a failed Wings call, which it answers with
 * the Wings error.
 */
final readonly class ServerStateUnavailable
{
    public const string SUSPENDED = 'suspended';

    public const string NODE_MAINTENANCE = 'node_maintenance';

    public const string INSTALLING = 'installing';

    public const string RESTORING_BACKUP = 'restoring_backup';

    public const string TRANSFERRING = 'transferring';

    public const string DAEMON_ERROR = 'daemon_error';

    public const array CODES = [
        self::SUSPENDED,
        self::NODE_MAINTENANCE,
        self::INSTALLING,
        self::RESTORING_BACKUP,
        self::TRANSFERRING,
        self::DAEMON_ERROR,
    ];

    public function __construct(public string $code, public int $status, public string $detail) {}

    /** The state that keeps the server's resources from being read, or null when they can be read. */
    public static function forServer(Server $server): ?self
    {
        $code = match (true) {
            $server->isSuspended() => self::SUSPENDED,
            $server->node->isUnderMaintenance() => self::NODE_MAINTENANCE,
            ! $server->isInstalled() => self::INSTALLING,
            $server->status === Server::STATUS_RESTORING_BACKUP => self::RESTORING_BACKUP,
            $server->transfer !== null => self::TRANSFERRING,
            default => null,
        };

        if ($code === null) {
            return null;
        }

        $conflict = new ServerStateConflictException($server);

        return new self($code, $conflict->getStatusCode(), $conflict->getMessage());
    }

    public static function fromDaemon(DaemonConnectionException $exception): self
    {
        return new self(self::DAEMON_ERROR, $exception->getStatusCode(), $exception->getMessage());
    }

    /** @param array{code: string, status: int, detail: string} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['code'], $data['status'], $data['detail']);
    }

    /** @return array{code: string, status: int, detail: string} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'status' => $this->status, 'detail' => $this->detail];
    }
}
