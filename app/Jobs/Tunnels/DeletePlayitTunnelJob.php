<?php

declare(strict_types=1);

namespace Pterodactyl\Jobs\Tunnels;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Exceptions\Tunnels\PlayitException;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Tunnels\PlayitClient;

/**
 * Deletes a tunnel on playit.gg after its server was deleted or its tunnel replaced. It carries
 * only the node and tunnel ids; the agent secret is read from the node when the job runs.
 */
class DeletePlayitTunnelJob implements ShouldQueue
{
    use Queueable;

    /** @var list<int> */
    private const array BACKOFF = [60, 300, 900, 3600];

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public readonly int $nodeId, public readonly string $playitTunnelId) {}

    public function handle(PlayitClient $client): void
    {
        $node = Node::query()->find($this->nodeId);
        if (! $node instanceof Node || ! $node->hasPlayitAgent()) {
            return;
        }

        try {
            $client->deleteTunnel((string) $node->playit_secret, $this->playitTunnelId);
        } catch (PlayitException $playitException) {
            // A read-only agent key will never be allowed to delete it, so do not retry.
            if ($playitException->reason !== PlayitClient::READ_ONLY && $this->attempts() < $this->tries) {
                $this->release(self::BACKOFF[$this->attempts() - 1] ?? 3600);

                return;
            }

            // Never fail the deletion it follows; leave a trace so the tunnel can be removed by hand.
            Log::warning(sprintf('Could not delete playit.gg tunnel %s on node #%d: %s', $this->playitTunnelId, $this->nodeId, $playitException->getMessage()));
        }
    }
}
