<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Entrance\EntranceSettings;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves mc-router.sh, so the commands on a node's Minecraft entrance card can download it on
 * the node. It holds no secrets: on the node it reads the daemon token from the Wings config.
 */
class MinecraftRouterScriptController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $file = EntranceSettings::scriptFile();
        throw_unless(is_file($file), NotFoundHttpException::class);

        return response()->file($file, [
            'Content-Type' => 'text/x-shellscript; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="mc-router.sh"',
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
