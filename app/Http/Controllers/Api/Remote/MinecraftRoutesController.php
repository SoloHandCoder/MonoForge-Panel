<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Remote;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Api\Remote\RemoteRequestNode;
use Pterodactyl\Services\Entrance\MinecraftEntrance;

/**
 * Used by `scripts/monoforge/mc-router.sh` on the node every minute, authenticated with the
 * node's daemon token like Wings: Gate's whole config for the node's shared Minecraft entrance.
 * Only the authenticated node's own servers are ever in it.
 */
class MinecraftRoutesController extends Controller
{
    public function __invoke(Request $request, MinecraftEntrance $entrance): JsonResponse
    {
        $node = RemoteRequestNode::get($request);
        $enabled = $node->hasMinecraftEntrance();
        $gate = $enabled ? $entrance->gateConfig($node) : null;

        if ($enabled) {
            $entrance->touch($node);
        }

        return new JsonResponse([
            'enabled' => $enabled,
            'routes' => $gate === null ? 0 : count($gate['config']['lite']['routes']) - 1,
            'generated_at' => CarbonImmutable::now()->toAtomString(),
            'gate' => $gate,
        ], headers: ['Cache-Control' => 'no-store, private']);
    }
}
