<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Nodes;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Exceptions\Tunnels\PlayitException;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Nodes\GetPlayitSettingsRequest;
use Pterodactyl\Http\Requests\Api\Admin\Nodes\TestPlayitConnectionRequest;
use Pterodactyl\Http\Requests\Api\Admin\Nodes\UpdatePlayitSettingsRequest;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Tunnels\PlayitClient;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Nodes', 'Create, update, retrieve, and delete Wings nodes.')]
class PlayitController extends AdminApiController
{
    public const string INVALID_KEY_MESSAGE = 'playit rejected this agent key. Use the secret_key from the config file the running agent uses (often /etc/playit/playit.toml), without quotes.';

    private const array SETTINGS_EXAMPLE = [
        'agent_id' => '5b9d1b0e-3c1f-4e8a-9a51-1f0e7d2c6b44',
        'secret_set' => true,
    ];

    private const array TEST_EXAMPLE = [
        'success' => true,
        'message' => 'Connected to playit.gg. The agent has 3 tunnels.',
        'tunnel_count' => 3,
    ];

    /**
     * Return the node's playit.gg agent. The secret is never returned, only whether one is stored.
     */
    #[Endpoint('Get node playit.gg agent', 'Returns the playit.gg agent the panel creates server tunnels on for this node, and whether its secret is stored.')]
    #[ScribeResponse(self::SETTINGS_EXAMPLE, description: 'playit.gg agent returned.')]
    public function show(GetPlayitSettingsRequest $request, Node $node): JsonResponse
    {
        return new JsonResponse($this->settings($node));
    }

    /**
     * Set or remove the node's playit.gg agent.
     */
    #[Endpoint('Update node playit.gg agent', 'Sets the playit.gg agent id and secret for this node. A blank secret keeps the stored one; a null agent id removes the agent and its secret, and new servers on the node get no tunnel. Values pasted from playit.toml are cleaned first: surrounding whitespace, one pair of quotes and a leading `secret_key =` or `agent_id =` are removed. A secret that then does not look like a key (16 to 512 letters, digits, `-` or `_`) is refused with 422.')]
    #[ScribeResponse(self::SETTINGS_EXAMPLE, description: 'playit.gg agent updated.')]
    public function update(UpdatePlayitSettingsRequest $request, Node $node): JsonResponse
    {
        $agentId = $request->filled('agent_id') ? mb_strtolower($request->string('agent_id')->trim()->toString()) : null;
        $secret = $request->string('secret')->trim()->toString();

        $node->playit_agent_id = $agentId;
        if ($agentId === null) {
            $node->playit_secret = null;
        } elseif ($secret !== '') {
            $node->playit_secret = $secret;
        }

        $node->save();

        return new JsonResponse($this->settings($node));
    }

    /**
     * Check the stored agent secret against playit.gg by listing the agent's tunnels.
     */
    #[Endpoint('Test node playit.gg agent', "Lists the agent's tunnels on playit.gg to check the stored agent id and secret. Always answers 200; `success` says whether playit accepted them.")]
    #[ScribeResponse(self::TEST_EXAMPLE, description: 'Connection checked.')]
    public function test(TestPlayitConnectionRequest $request, Node $node, PlayitClient $client): JsonResponse
    {
        if (! $node->hasPlayitAgent()) {
            return new JsonResponse(['success' => false, 'message' => 'Save an agent id and secret first.', 'tunnel_count' => null]);
        }

        try {
            $count = count($client->listTunnels((string) $node->playit_secret, (string) $node->playit_agent_id));
        } catch (PlayitException $playitException) {
            $message = $playitException->reason === 'InvalidAgentKey' ? self::INVALID_KEY_MESSAGE : $playitException->getMessage();

            return new JsonResponse(['success' => false, 'message' => $message, 'tunnel_count' => null]);
        }

        return new JsonResponse([
            'success' => true,
            'message' => sprintf('Connected to playit.gg. The agent has %d %s.', $count, $count === 1 ? 'tunnel' : 'tunnels'),
            'tunnel_count' => $count,
        ]);
    }

    /**
     * @return array{agent_id: string|null, secret_set: bool}
     */
    private function settings(Node $node): array
    {
        return [
            'agent_id' => $node->playit_agent_id,
            'secret_set' => is_string($node->playit_secret) && $node->playit_secret !== '',
        ];
    }
}
