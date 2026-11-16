<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Nodes;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Exceptions\Tunnels\PlayitException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Nodes\GetPlayitSwitchRequest;
use Pterodactyl\Http\Requests\Api\Admin\Nodes\ManagePlayitSwitchRequest;
use Pterodactyl\Models\Billing\PlayitAgentClaim;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Tunnels\PlayitAgentSwitchService;

/**
 * "Set up automatic tunnels" on a node's playit card. See PlayitAgentSwitchService for the flow.
 * No response ever contains the agent secret; the switch token is returned once, by `token`.
 */
#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Nodes', 'Create, update, retrieve, and delete Wings nodes.')]
#[ResponseField('mode', 'string', 'The playit mode of the node: `read_only`, `self_managed` or null.', nullable: true)]
#[ResponseField('switched_at', 'string', 'When the node switched to the self-managed agent.', nullable: true)]
#[ResponseField('claim', 'object', 'The claim of the node, or null before "Set up automatic tunnels" was started.', nullable: true)]
#[ResponseField('claim.claim_url', 'string', 'The playit.gg URL to accept the claim at, while it waits.', nullable: true)]
#[ResponseField('claim.expires_at', 'string', 'When the waiting claim expires.', nullable: true)]
#[ResponseField('claim.agent_id', 'string', 'The claimed agent, once the claim was exchanged.', nullable: true)]
#[ResponseField('claim.token_expires_at', 'string', 'When the unused switch token expires.', nullable: true)]
#[ResponseField('claim.fetched_at', 'string', 'When the switch script on the node fetched the secret.', nullable: true)]
#[ResponseField('claim.report', 'string', 'What the script reported: `switched` or `rolled_back`.', nullable: true)]
#[ResponseField('claim.reported_at', 'string', 'When the script reported.', nullable: true)]
#[ResponseField('claim.last_error', 'string', 'The last playit.gg error while checking the claim.', nullable: true)]
#[ResponseField('old_tunnels[].name', 'string', 'The tunnel name on playit.gg.', nullable: true)]
#[ResponseField('old_tunnels[].address', 'string', 'The address players used.', nullable: true)]
#[ResponseField('old_tunnels[].local_port', 'integer', 'The local port it pointed at.', nullable: true)]
#[ResponseField('old_tunnels[].server_id', 'integer', 'The server it was linked to.', nullable: true)]
#[ResponseField('old_tunnels[].server_name', 'string', 'The name of that server.', nullable: true)]
#[ResponseField('script.sha256', 'string', 'The SHA-256 of the switch script, to check the download.', nullable: true)]
class PlayitSwitchController extends AdminApiController
{
    private const array STATE_EXAMPLE = [
        'mode' => 'self_managed',
        'self_managed' => true,
        'switched_at' => '2026-11-23T08:15:00+00:00',
        'can_undo' => true,
        'claim' => [
            'status' => 'switched',
            'claim_url' => null,
            'expires_at' => null,
            'agent_id' => '7c1e2d3f-4a5b-4c6d-8e9f-0a1b2c3d4e5f',
            'token_expires_at' => null,
            'fetched_at' => '2026-11-23T08:14:10+00:00',
            'report' => 'switched',
            'reported_at' => '2026-11-23T08:15:00+00:00',
            'last_error' => null,
        ],
        'old_tunnels' => [[
            'id' => 1,
            'playit_tunnel_id' => '0c8f1f4e-6f7b-4a2e-9a1d-2f6e3c1b7a90',
            'name' => 'survival',
            'address' => 'abc-def.joinmc.link',
            'local_port' => 25565,
            'server_id' => 12,
            'server_name' => 'Survival',
        ]],
        'old_tunnels_removed' => 0,
        'script' => [
            'url' => 'https://panel.example.com/scripts/playit-switch-agent.sh',
            'sha256' => '9f2c1d7e4b3a5c6d8e0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d',
        ],
    ];

    private const array RECONCILE_EXAMPLE = [
        ...self::STATE_EXAMPLE,
        'reconcile' => [
            'action' => 'finished',
            'message' => 'The node had reported the switch; the panel now uses the new agent.',
            'agent_online' => true,
            'tunnels' => 2,
            'created' => 2,
            'active' => 1,
            'waiting_for_agent' => 0,
            'last_error' => null,
        ],
    ];

    private const array TOKEN_EXAMPLE = [
        'token' => '3f9a1c7e5b2d4f6a8c0e1b3d5f7a9c2e4b6d8f0a1c3e5b7d',
        'expires_at' => '2026-11-23T08:29:00+00:00',
    ];

    private const array REMOVE_EXAMPLE = [
        'removed' => 2,
        'remaining' => 1,
        'manual' => true,
        'message' => 'Removed 2 old tunnels. playit.gg only lets the old key read, so delete the rest in the playit dashboard, then choose "I removed them".',
    ];

    #[Endpoint('Get node playit.gg switch', "Returns where \"Set up automatic tunnels\" stands for this node: the node's playit mode (`read_only`, `self_managed` or null), when it switched to the self-managed agent, whether the switch can be undone, the current claim (its status: `waiting_for_visit`, `waiting_for_user`, `accepted`, `rejected`, `expired`, `ready` or `switched`; the claim URL while it waits; whether the node's script fetched the secret and what it reported), the old agent's tunnels still to remove, and the URL and SHA-256 of the switch script. Never contains the agent secret or a token. Only reads the panel's database.")]
    #[ScribeResponse(self::STATE_EXAMPLE, description: 'Switch state returned.')]
    public function show(GetPlayitSwitchRequest $request, Node $node, PlayitAgentSwitchService $switches): JsonResponse
    {
        return new JsonResponse($switches->state($node));
    }

    #[Endpoint('Start node playit.gg claim', 'Registers a fresh claim code (up to 20 hex characters) for a self-managed playit.gg agent and returns the switch state with the claim URL the administrator opens to accept it. Any unfinished claim of the node is replaced. The node keeps its current agent until the switch finishes. Answers 400 when playit.gg cannot be reached or refuses the claim.')]
    #[ScribeResponse(self::STATE_EXAMPLE, description: 'Claim started.')]
    public function start(ManagePlayitSwitchRequest $request, Node $node, PlayitAgentSwitchService $switches): JsonResponse
    {
        try {
            $switches->start($node);
        } catch (PlayitException $playitException) {
            throw new DisplayException($playitException->adminMessage(), $playitException);
        }

        Activity::event('admin:node.playit-claim')->subject($node)->property('name', $node->name)->log();

        return new JsonResponse($switches->state($node));
    }

    #[Endpoint('Check node playit.gg claim', 'Asks playit.gg whether the claim was accepted and, once it was, exchanges it for the new agent secret (stored encrypted, never returned) and reads the agent id. Returns the switch state; playit errors are reported in `claim.last_error`. Poll it every few seconds while the claim waits.')]
    #[ScribeResponse(self::STATE_EXAMPLE, description: 'Claim checked.')]
    public function check(ManagePlayitSwitchRequest $request, Node $node, PlayitAgentSwitchService $switches): JsonResponse
    {
        $switches->check($this->claim($node));

        return new JsonResponse($switches->state($node));
    }

    #[Endpoint('Create node playit.gg switch token', "Creates the one-time token the administrator pastes into the node's switch script (`playit-switch-agent.sh`), which fetches the new agent secret with it. The token is returned only here, works once and expires after 15 minutes; a new token replaces the old one. Answers 400 until the claim is accepted.")]
    #[ScribeResponse(self::TOKEN_EXAMPLE, description: 'Token created.')]
    public function token(ManagePlayitSwitchRequest $request, Node $node, PlayitAgentSwitchService $switches): JsonResponse
    {
        $claim = $this->claim($node);
        $token = $switches->issueToken($claim);

        Activity::event('admin:node.playit-token')->subject($node)->property('name', $node->name)->log();

        return new JsonResponse([
            'token' => $token,
            'expires_at' => $claim->refresh()->token_expires_at?->toAtomString() ?? CarbonImmutable::now()->toAtomString(),
        ]);
    }

    #[Endpoint('Finish node playit.gg switch', "Makes the claimed self-managed agent the node's agent, as the switch script does when it reports success: the old agent's tunnels are listed for removal, the old key is kept so the switch can be undone, and every server on the node gets a new tunnel (new addresses). Only use it once the node runs the new agent. Answers 400 until the claim is accepted.")]
    #[ScribeResponse(self::STATE_EXAMPLE, description: 'Switch finished.')]
    public function finish(ManagePlayitSwitchRequest $request, Node $node, PlayitAgentSwitchService $switches): JsonResponse
    {
        $switches->finish($this->claim($node));

        Activity::event('admin:node.playit-switch')->subject($node)->property('name', $node->name)->log();

        return new JsonResponse($switches->state($node->refresh()));
    }

    #[Endpoint('Check node playit.gg switch status', "Brings the panel in line with the agent the node really runs: a report of the node's switch script the panel never acted on (a lost answer) is applied, so `switched` finishes the switch and `rolled_back` undoes it; the node's key is checked against playit.gg run data (a wrong agent id is corrected); missing tunnels are queued and one is tried. `reconcile.action` is `none`, `finished`, `undone` or `agent_corrected`; `reconcile.agent_online` says whether playit.gg accepts tunnels for the agent.")]
    #[ScribeResponse(self::RECONCILE_EXAMPLE, description: 'Status checked.')]
    #[ResponseField('reconcile.agent_online', 'boolean', "Whether playit.gg accepts tunnels for the node's agent; null without an agent.", nullable: true)]
    #[ResponseField('reconcile.last_error', 'string', 'The last tunnel error.', nullable: true)]
    public function reconcile(ManagePlayitSwitchRequest $request, Node $node, PlayitAgentSwitchService $switches): JsonResponse
    {
        $result = $switches->reconcile($node);

        Activity::event('admin:node.playit-reconcile')->subject($node)->property(['name' => $node->name, 'action' => $result['action']])->log();

        return new JsonResponse([...$switches->state($node->refresh()), 'reconcile' => $result]);
    }

    #[Endpoint('Undo node playit.gg switch', "Puts the node's previous playit.gg agent back after `playit-switch-agent.sh --rollback` on the node: the tunnels made on the self-managed agent are deleted on playit (best effort) and servers get their old tunnels and addresses back. Answers 400 when the node has no earlier agent.")]
    #[ScribeResponse(self::STATE_EXAMPLE, description: 'Switch undone.')]
    public function undo(ManagePlayitSwitchRequest $request, Node $node, PlayitAgentSwitchService $switches): JsonResponse
    {
        $switches->undo($node);

        Activity::event('admin:node.playit-undo')->subject($node)->property('name', $node->name)->log();

        return new JsonResponse($switches->state($node->refresh()));
    }

    #[Endpoint('Remove old node playit.gg tunnels', "Deletes the previous agent's tunnels on playit.gg. When playit refuses because the old key is read-only, `manual` is true: delete them in the playit dashboard and then dismiss the list.")]
    #[ScribeResponse(self::REMOVE_EXAMPLE, description: 'Old tunnels removed.')]
    public function removeOld(ManagePlayitSwitchRequest $request, Node $node, PlayitAgentSwitchService $switches): JsonResponse
    {
        $result = $switches->removeOldTunnels($node);

        Activity::event('admin:node.playit-old-tunnels')->subject($node)->property(['name' => $node->name, 'removed' => $result['removed']])->log();

        $message = sprintf('Removed %d old %s.', $result['removed'], $result['removed'] === 1 ? 'tunnel' : 'tunnels');
        if ($result['manual'] && $result['remaining'] > 0) {
            $message .= ' playit.gg only lets the old key read, so delete the rest in the playit dashboard, then choose "I removed them".';
        } elseif ($result['remaining'] > 0) {
            $message .= sprintf(' %d could not be removed; try again later.', $result['remaining']);
        }

        return new JsonResponse([...$result, 'message' => $message]);
    }

    #[Endpoint('Dismiss old node playit.gg tunnels', "Stops listing the previous agent's tunnels, once the administrator deleted them in the playit dashboard. Changes nothing on playit.gg.")]
    #[ScribeResponse(self::STATE_EXAMPLE, description: 'Old tunnels dismissed.')]
    public function dismissOld(ManagePlayitSwitchRequest $request, Node $node, PlayitAgentSwitchService $switches): JsonResponse
    {
        $switches->dismissOldTunnels($node);

        Activity::event('admin:node.playit-old-tunnels-dismiss')->subject($node)->property('name', $node->name)->log();

        return new JsonResponse($switches->state($node));
    }

    private function claim(Node $node): PlayitAgentClaim
    {
        $claim = PlayitAgentClaim::query()->where('node_id', $node->id)->first();
        throw_unless($claim instanceof PlayitAgentClaim, DisplayException::class, 'Start setting up automatic tunnels first.');

        return $claim;
    }
}
