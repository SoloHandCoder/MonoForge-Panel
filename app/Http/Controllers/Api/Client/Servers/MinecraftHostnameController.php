<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\GetServerRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Network\UpdateMinecraftHostnameRequest;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Entrance\EntranceHostnames;
use Pterodactyl\Services\Entrance\MinecraftEntrance;

/**
 * The `<name>` in `<name>.<domain>`, the address of a Minecraft server joined through its
 * node's shared Minecraft entrance.
 */
#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Server Network', 'View and manage network allocations for an accessible server.')]
class MinecraftHostnameController extends ClientApiController
{
    private const array EXAMPLE = [
        'routed' => true,
        'hostname' => 'survival',
        'domain' => 'play.example.com',
        'address' => 'survival.play.example.com',
    ];

    #[Endpoint('Get server Minecraft hostname', "Whether the server is joined through its node's shared Minecraft entrance (`routed`), and its name there: players join `<hostname>.<domain>`. `address` is the full address players use, with the port when the entrance needs one; null while the entrance has no address yet. Not routed: every other field is null.")]
    #[ResponseField('routed', 'boolean', "Whether the server is joined through the node's shared Minecraft entrance.")]
    #[ResponseField('hostname', 'string', 'The name in front of the domain.', nullable: true)]
    #[ResponseField('domain', 'string', 'The base domain of the entrance.', nullable: true)]
    #[ResponseField('address', 'string', 'The address players join with.', nullable: true)]
    #[ScribeResponse(self::EXAMPLE, description: 'Hostname returned.')]
    public function show(GetServerRequest $request, Server $server): JsonResponse
    {
        return new JsonResponse($this->payload($server));
    }

    #[Endpoint('Update server Minecraft hostname', 'Changes the name in front of the domain. 3 to 40 lowercase letters, digits and single hyphens; reserved names (www, play, admin, api, mail and similar) and names another server uses are refused. Players using the old name can no longer join with it once the node syncs its routes (within a minute).')]
    #[BodyParam('hostname', 'string', 'The new name, such as survival.', required: true, example: 'survival')]
    #[ResponseField('routed', 'boolean', 'Always true.')]
    #[ResponseField('hostname', 'string', 'The name in front of the domain.', nullable: true)]
    #[ResponseField('domain', 'string', 'The base domain of the entrance.', nullable: true)]
    #[ResponseField('address', 'string', 'The address players join with.', nullable: true)]
    #[ScribeResponse(self::EXAMPLE, description: 'Hostname saved.')]
    public function update(UpdateMinecraftHostnameRequest $request, Server $server): JsonResponse
    {
        throw_unless(MinecraftEntrance::isRouted($server), ValidationException::withMessages([
            'hostname' => "This server is not joined through its node's shared Minecraft entrance.",
        ]));

        $old = EntranceHostnames::for($server);
        $new = mb_strtolower(mb_trim($request->string('hostname')->toString()));

        if ($new !== $old) {
            $server->forceFill(['mc_hostname' => $new])->saveQuietly();

            Activity::event('server:settings.minecraft-hostname')
                ->subject($server)
                ->property(['old' => $old, 'new' => $new])
                ->log();
        }

        return new JsonResponse($this->payload($server));
    }

    /**
     * @return array{routed: bool, hostname: string|null, domain: string|null, address: string|null}
     */
    private function payload(Server $server): array
    {
        if (! MinecraftEntrance::isRouted($server)) {
            return ['routed' => false, 'hostname' => null, 'domain' => null, 'address' => null];
        }

        return [
            'routed' => true,
            'hostname' => EntranceHostnames::for($server),
            'domain' => $server->node->mc_entrance_domain,
            'address' => MinecraftEntrance::address($server),
        ];
    }
}
