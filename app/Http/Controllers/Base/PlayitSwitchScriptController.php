<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Tunnels\PlayitAgentSwitchService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the node script that switches playit.gg to the self-managed agent, so the install
 * command on a node's playit card can download it. It holds no secrets; the node fetches the
 * agent secret separately with a one-time token.
 */
class PlayitSwitchScriptController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $file = PlayitAgentSwitchService::scriptFile();
        throw_unless(is_file($file), NotFoundHttpException::class);

        return response()->file($file, [
            'Content-Type' => 'text/x-shellscript; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="playit-switch-agent.sh"',
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
