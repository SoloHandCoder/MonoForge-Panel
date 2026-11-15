<?php

declare(strict_types=1);

namespace Pterodactyl\Actions\Servers;

use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Assistant\CrashDoctor\CrashRecorder;
use Pterodactyl\Services\Sleep\ServerSleep;

final readonly class SendServerPower implements SendsServerPower
{
    public function __construct(private ServerSleep $sleep) {}

    public function send(Server $server, string $signal): void
    {
        // The crash doctor must not take this stop, or a start that fails, for something else.
        CrashRecorder::expectPowerChange($server, $signal);
        Daemon::server($server)->power($signal);

        // Starting a sleeping server wakes it, whoever does it (pay-as-you-play).
        if (in_array($signal, ['start', 'restart'], true)) {
            $this->sleep->markAwake($server);
        }

        Activity::event(mb_strtolower("server:power.{$signal}"))->subject($server)->log();
    }
}
