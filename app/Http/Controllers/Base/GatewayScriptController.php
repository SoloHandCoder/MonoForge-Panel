<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Gateway\GameGatewayService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves gateway.sh, so the commands on a node's game gateway card can download it on the VPS
 * and the node. It holds no secrets: each machine makes its own WireGuard key.
 */
class GatewayScriptController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $file = GameGatewayService::scriptFile();
        throw_unless(is_file($file), NotFoundHttpException::class);

        return response()->file($file, [
            'Content-Type' => 'text/x-shellscript; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="gateway.sh"',
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
