<?php

declare(strict_types=1);

namespace Pterodactyl\Actions\Servers;

use Exception;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Pterodactyl\Contracts\Servers\TogglesServerSuspension;
use Pterodactyl\Events\Server\OperationCompleted;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Billing\ServerSubscription;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Billing\BillingSuspensionScope;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class ToggleServerSuspension implements TogglesServerSuspension
{
    public const string ACTION_SUSPEND = TogglesServerSuspension::ACTION_SUSPEND;

    public const string ACTION_UNSUSPEND = TogglesServerSuspension::ACTION_UNSUSPEND;

    public function toggle(Server $server, string $action = self::ACTION_SUSPEND): void
    {
        throw_unless(in_array($action, [self::ACTION_SUSPEND, self::ACTION_UNSUSPEND], true), InvalidArgumentException::class, "Unsupported suspension action [$action].");
        $isSuspending = $action === self::ACTION_SUSPEND;

        // Repeating the current state does not require another Wings sync.
        if ($isSuspending === $server->isSuspended()) {
            $this->takeOverFromBilling($server);

            return;
        }

        // A transfer can race with Wings state synchronization, so reject it first.
        throw_if($server->transfer !== null, ConflictHttpException::class, 'Cannot toggle suspension status on a server that is currently being transferred.');
        $server->update(['status' => $isSuspending ? Server::STATUS_SUSPENDED : null]);

        try {
            Daemon::server($server)->sync();
        } catch (Exception $exception) {
            // Restore the Panel state if Wings could not accept the update.
            $server->update(['status' => $isSuspending ? null : Server::STATUS_SUSPENDED]);
            throw $exception;
        }

        $this->takeOverFromBilling($server);

        // Only a state change Wings accepted is reported; repeats and reverted attempts are not.
        Event::dispatch(new OperationCompleted($server->uuid, $isSuspending ? 'suspend' : 'unsuspend', true, $server->uuid));
    }

    /**
     * Anyone but billing who changes (or repeats, say an admin suspending an unpaid server for
     * abuse) the suspension now owns it: a later payment must not lift it. Only called once
     * the change went through, so a refused unsuspend leaves billing's suspension billing's.
     */
    private function takeOverFromBilling(Server $server): void
    {
        if (! BillingSuspensionScope::active()) {
            ServerSubscription::query()->where('server_id', $server->id)->where('suspended_by_billing', true)->update(['suspended_by_billing' => false]);
        }
    }
}
