<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Remote;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Api\Remote\PlayitAgentSecretRequest;
use Pterodactyl\Http\Requests\Api\Remote\PlayitAgentSwitchReportRequest;
use Pterodactyl\Http\Requests\Api\Remote\RemoteRequestNode;
use Pterodactyl\Models\Billing\PlayitAgentClaim;
use Pterodactyl\Services\Tunnels\PlayitAgentSwitchService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Used by `scripts/monoforge/playit-switch-agent.sh` on the node, authenticated with the node's
 * daemon token (read from its Wings config) and the one-time token the administrator pasted.
 */
class PlayitAgentSwitchController extends Controller
{
    /**
     * The new agent's secret, once per token. The response is never cached or logged.
     */
    public function secret(PlayitAgentSecretRequest $request, PlayitAgentSwitchService $switches): JsonResponse
    {
        $node = RemoteRequestNode::get($request);
        $secret = $switches->fetch($node, $request->string('token')->toString());

        // Unknown, expired and used tokens look the same.
        throw_if($secret === null, NotFoundHttpException::class, "This switch token is unknown, expired or already used. Create a new one on the node's playit card.");

        Activity::event('admin:node.playit-secret-fetched')->subject($node)->property('name', $node->name)->log();

        return new JsonResponse($secret, headers: ['Cache-Control' => 'no-store, private']);
    }

    /**
     * How the node's tunnels are doing on the new agent, so the script can tell whether playit
     * accepts tunnels for it.
     */
    public function status(PlayitAgentSecretRequest $request, PlayitAgentSwitchService $switches): JsonResponse
    {
        $status = $switches->status(RemoteRequestNode::get($request), $request->string('token')->toString());
        throw_if($status === null, NotFoundHttpException::class, 'This switch token was not used to fetch a secret recently.');

        return new JsonResponse($status);
    }

    /**
     * How the switch went on the node: `switched` finishes the switch in the panel, `rolled_back`
     * undoes it.
     */
    public function report(PlayitAgentSwitchReportRequest $request, PlayitAgentSwitchService $switches): JsonResponse
    {
        $node = RemoteRequestNode::get($request);
        $outcome = $request->string('outcome')->toString();

        try {
            $accepted = $switches->report($node, $request->string('token')->toString(), $outcome);
        } catch (DisplayException $displayException) {
            return new JsonResponse(['success' => false, 'message' => $displayException->getMessage()], 409);
        }

        throw_unless($accepted, NotFoundHttpException::class, 'This switch token was not used to fetch a secret recently.');

        if ($outcome === PlayitAgentClaim::REPORT_SWITCHED) {
            Activity::event('admin:node.playit-switch-reported')->subject($node)->property('name', $node->name)->log();
        } else {
            Activity::event('admin:node.playit-rollback-reported')->subject($node)->property('name', $node->name)->log();
        }

        return new JsonResponse(['success' => true, 'message' => $outcome === PlayitAgentClaim::REPORT_SWITCHED
            ? 'The panel now creates tunnels on the new agent.'
            : 'The panel is back on the previous agent.']);
    }
}
