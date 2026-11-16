<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Client;

use Illuminate\Http\Request;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Http\Resources\Api\ApiResource;
use Pterodactyl\Http\Resources\Api\ApiResourceCollection;
use Pterodactyl\Http\Resources\Api\Includes\IncludedResource;
use Pterodactyl\Http\Resources\Api\NullResource;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Services\Games\GameResolver;
use Pterodactyl\Services\Servers\ServerAddress;
use UnexpectedValueException;

/**
 * @extends ClientResource<Server>
 */
#[ResponseField('server_identifier', schema: ['type' => 'string', 'pattern' => '^serv_[a-zA-Z0-9]+$', 'example' => 'serv_1a2b3c4d'])]
#[ResponseField('description', example: 'Survival world', nullable: true)]
#[ResponseField('status', schema: ['type' => 'string', 'nullable' => true, 'enum' => ['installing', 'install_failed', 'reinstall_failed', 'suspended', 'restoring_backup', null], 'example' => null])]
#[ResponseField('limits.threads', schema: ['type' => 'string', 'nullable' => true, 'example' => '1,2'])]
#[ResponseField('feature_limits.databases', 'integer', example: 5, nullable: true)]
#[ResponseField('feature_limits.allocations', 'integer', example: 5, nullable: true)]
#[ResponseField('feature_limits.backups', 'integer', example: 5, nullable: true)]
#[ResponseField('egg_features', schema: ['type' => 'array', 'nullable' => true, 'items' => ['type' => 'string'], 'example' => ['eula']])]
#[ResponseField('playit_address', type: 'string', description: 'The public address players connect to: the game gateway address (`<gateway host>:<port>`, the port left out for Minecraft Java on 25565) when the server is on a node in gateway mode and its port is forwarded, otherwise the address of its live playit.gg tunnel. Null when there is none, and the primary allocation is the address unless `address_pending` is true. It is never the address of the node itself.', example: 'xyz.joinmc.link', nullable: true)]
#[ResponseField('address_source', type: 'string', description: 'Where `playit_address` comes from: `gateway` (the game gateway VPS) or `playit` (a playit.gg tunnel); null when there is no public address.', example: 'playit', enum: ['gateway', 'playit', null], nullable: true)]
#[ResponseField('address_pending', type: 'boolean', description: 'True when the server is on a node with a playit.gg agent or the game gateway but has no public address yet (no live tunnel, or a port the gateway does not forward): players should be shown "Address pending" rather than the node allocation, which is not reachable from outside.', example: false)]
#[ResponseField('egg_tags', schema: ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['minecraft']])]
#[ResponseField('game', type: 'string', description: "The slug of the game the server's egg belongs to in Admin > Games (minecraft, terraria or a custom game), or of the built-in game the panel detects for an egg no game claims; null when unknown.", example: 'minecraft', nullable: true)]
#[ResponseField('game_features', description: 'What the game adds to the server pages: plugins, versions, player_count, world_settings.', schema: ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['plugins', 'versions', 'player_count']])]
#[ResponseField('extra_addresses', description: "The server's extra public addresses, such as voice chat: its live extra playit.gg tunnels, or on a game gateway node the extra ports of its game that match a forwarded allocation. The public page never shows them.", schema: [
    'type' => 'array',
    'items' => [
        'type' => 'object',
        'required' => ['key', 'label', 'address', 'protocol'],
        'properties' => [
            'key' => ['type' => 'string', 'example' => 'voice'],
            'label' => ['type' => 'string', 'example' => 'Voice chat'],
            'address' => ['type' => 'string', 'example' => 'abc.ply.gg:12345'],
            'protocol' => ['type' => 'string', 'enum' => ['tcp', 'udp', 'both'], 'example' => 'udp'],
        ],
    ],
])]
class ServerResource extends ClientResource
{
    protected array $eagerLoads = ['egg.variables', 'egg.configFrom', 'egg.tags', 'egg.game', 'serverVariables', 'node', 'transfer', 'subusers', 'allocation', 'tunnel', 'tunnels'];

    protected array $includeRelations = [
        'allocations' => ['relation' => 'allocations', 'resource' => AllocationResource::class],
        'egg' => ['relation' => 'egg', 'resource' => EggResource::class],
        'subusers' => ['relation' => 'subusers', 'resource' => SubuserResource::class],
    ];

    protected array $defaultIncludes = ['allocations', 'variables'];

    protected array $availableIncludes = ['egg', 'subusers'];

    public function resourceName(): string
    {
        return Server::RESOURCE_NAME;
    }

    public function toArray(Request $request): array
    {
        $user = $this->getUser();
        $server = $this->resource;

        $server->loadMissing(['egg.tags', 'egg.game', 'node', 'transfer', 'tunnel']);

        $egg = $server->egg ?? throw new UnexpectedValueException('The server does not have an egg relationship.');
        $addresses = resolve(ServerAddress::class);
        $playit = $addresses->main($server);
        $game = GameResolver::forEgg($egg);

        return [
            'server_owner' => $user->id === $server->owner_id,
            'identifier' => config('pterodactyl.features.new_server_identifiers')
                ? $server->identifier
                : $server->uuidShort,
            '__deprecated_uuid_short' => $server->uuidShort,
            'server_identifier' => $server->identifier,
            'internal_id' => $server->id,
            'uuid' => $server->uuid,
            'name' => $server->name,
            'node' => $server->node->name,
            'is_node_under_maintenance' => $server->node->isUnderMaintenance(),
            'sftp_details' => [
                'ip' => $server->node->fqdn,
                'port' => $server->node->daemonSFTP,
            ],
            'description' => $server->description,
            'limits' => [
                'memory' => $server->memory,
                'swap' => $server->swap,
                'disk' => $server->disk,
                'io' => $server->io,
                'cpu' => $server->cpu,
                'threads' => $server->threads,
                'oom_disabled' => $server->oom_disabled,
            ],
            'invocation' => $server->startupCommand(! $user->can(Permissions::StartupRead->value, $server)),
            'docker_image' => $server->image,
            'egg_features' => $egg->inherit_features,
            'egg_tags' => $egg->tagSlugs(),
            'feature_limits' => [
                'databases' => $server->database_limit,
                'allocations' => $server->allocation_limit,
                'backups' => $server->backup_limit,
            ],
            'status' => $server->status,
            // This field is deprecated, please use "status".
            'is_suspended' => $server->isSuspended(),
            // This field is deprecated, please use "status".
            'is_installing' => ! $server->isInstalled(),
            'is_transferring' => ($server->transfer) !== null,
            'skip_scripts' => $server->skip_scripts,
            'playit_address' => $playit,
            'address_source' => $addresses->source($server),
            'address_pending' => $addresses->pending($server),
            'game' => $game?->slug,
            'game_features' => $game?->features() ?? [],
            'extra_addresses' => $addresses->extras($server),
        ];
    }

    protected function resolveInclude(string $include): ?IncludedResource
    {
        return match ($include) {
            'allocations' => $this->includeAllocations(),
            'variables' => $this->includeVariables(),
            'egg' => $this->includeEgg(),
            'subusers' => $this->includeSubusers(),
            default => parent::resolveInclude($include),
        };
    }

    private function includeAllocations(): ApiResourceCollection
    {
        // While we include this permission, we do need to actually handle it slightly different here
        // for the purpose of keeping things functionally working. If the user doesn't have read permissions
        // for the allocations we'll only return the primary server allocation, and any notes associated
        // with it will be hidden.
        //
        // This allows us to avoid too much permission regression, without also hiding information that
        // is generally needed for the frontend to make sense when browsing or searching results.
        if (! $this->getUser()->can(Permissions::AllocationRead->value, $this->resource)) {
            $primary = clone ($this->resource->allocation ?? throw new UnexpectedValueException('The server does not have a primary allocation.'));
            $primary->notes = null;

            return $this->items([$primary], AllocationResource::class, Allocation::RESOURCE_NAME);
        }

        return $this->items($this->resource->loadMissing('allocations')->allocations, AllocationResource::class, Allocation::RESOURCE_NAME);
    }

    private function includeVariables(): ApiResourceCollection|NullResource
    {
        if (! $this->getUser()->can(Permissions::StartupRead->value, $this->resource)) {
            return $this->null();
        }

        return $this->items(
            $this->resource->resolvedVariables()->where('user_viewable', true)->values(),
            EggVariableResource::class,
            EggVariable::RESOURCE_NAME
        );
    }

    /**
     * @return ApiResource<Egg>
     */
    private function includeEgg(): ApiResource
    {
        $egg = $this->resource->egg ?? throw new UnexpectedValueException('The server does not have an egg relationship.');

        return $this->item($egg, EggResource::class, Egg::RESOURCE_NAME);
    }

    private function includeSubusers(): ApiResourceCollection|NullResource
    {
        if (! $this->getUser()->can(Permissions::UserRead->value, $this->resource)) {
            return $this->null();
        }

        return $this->items($this->resource->subusers, SubuserResource::class, Subuser::RESOURCE_NAME);
    }
}
