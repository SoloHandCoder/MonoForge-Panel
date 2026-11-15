<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Support\Fakes;

use Pterodactyl\Services\Query\MinecraftJavaPing;
use Pterodactyl\Services\Query\QueryConnection;
use Pterodactyl\Services\Query\QueryConnector;

/**
 * Plays a Minecraft Java server's side of the Server List Ping: it records what the client sent
 * and answers with the configured status (or nothing, or raw bytes).
 */
class FakeMinecraftServer implements QueryConnector
{
    /** @var list<array{host: string, port: int, timeout: float}> */
    public array $connections = [];

    /** What the client wrote, per connection. */
    public array $written = [];

    /** False refuses the connection (an offline server). */
    public bool $accepts = true;

    /** The bytes sent back; null builds them from $status. */
    public ?string $raw = null;

    /** @var array<string, mixed> */
    public array $status = [
        'version' => ['name' => 'Paper 1.21.4', 'protocol' => 769],
        'players' => ['max' => 20, 'online' => 0],
        'description' => 'A Minecraft Server',
    ];

    /** A status response packet carrying the JSON. */
    public static function response(string $json): string
    {
        $payload = "\x00".MinecraftJavaPing::varInt(mb_strlen($json, '8bit')).$json;

        return MinecraftJavaPing::varInt(mb_strlen($payload, '8bit')).$payload;
    }

    public function online(int $online, array $names = []): self
    {
        $this->status['players'] = [
            'max' => 20,
            'online' => $online,
            'sample' => array_map(static fn (string $name): array => ['name' => $name, 'id' => '00000000-0000-0000-0000-000000000000'], $names),
        ];

        return $this;
    }

    public function open(string $host, int $port, float $timeoutSeconds): ?QueryConnection
    {
        $this->connections[] = ['host' => $host, 'port' => $port, 'timeout' => $timeoutSeconds];
        if (! $this->accepts) {
            return null;
        }

        $index = count($this->connections) - 1;
        $this->written[$index] = '';
        $response = $this->raw ?? self::response(json_encode($this->status, JSON_THROW_ON_ERROR));

        return new class($this, $index, $response) implements QueryConnection
        {
            private int $offset = 0;

            public function __construct(private readonly FakeMinecraftServer $server, private readonly int $index, private readonly string $response) {}

            public function write(string $bytes): bool
            {
                $this->server->written[$this->index] .= $bytes;

                return true;
            }

            public function read(int $length): ?string
            {
                if ($this->offset + $length > mb_strlen($this->response, '8bit')) {
                    return null;
                }

                $chunk = mb_substr($this->response, $this->offset, $length, '8bit');
                $this->offset += $length;

                return $chunk;
            }

            public function close(): void {}
        };
    }
}
