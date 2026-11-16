<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client\Servers\Minecraft;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Exceptions\Games\MinecraftVersionException;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Minecraft\InstallMinecraftVersionRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Minecraft\ReadMinecraftVersionsRequest;
use Pterodactyl\Jobs\Games\InstallMinecraftVersionJob;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Games\GameResolver;
use Pterodactyl\Services\Games\Minecraft\MinecraftSoftware;
use Pterodactyl\Services\Games\Minecraft\MinecraftVersionCatalog;
use Pterodactyl\Services\Games\Minecraft\MinecraftVersionInstaller;
use Pterodactyl\Services\Plugins\PluginInstallProgress;
use Pterodactyl\Services\Plugins\ServerPlatformDetector;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Versions page of a Minecraft server: pick the software (Vanilla, Paper, Purpur, Folia,
 * Fabric, Forge, NeoForge) and a version, and the panel switches the server to it. Only for
 * servers whose egg belongs to Minecraft in Admin > Games (or is detected as Minecraft).
 */
#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Minecraft Versions', "Switch a Minecraft server's software and version.")]
class MinecraftVersionController extends ClientApiController
{
    private const array VERSIONS_SCHEMA = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'minecraft', 'type'],
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'The version to install: a Minecraft version, or the Forge/NeoForge version.'],
                'minecraft' => ['type' => 'string', 'description' => 'The Minecraft version it runs.'],
                'type' => ['type' => 'string', 'enum' => ['release', 'snapshot']],
            ],
        ],
    ];

    private const array PROGRESS_SCHEMA = [
        'type' => 'object',
        'required' => ['id', 'status', 'message', 'file_name', 'directory', 'version_number'],
        'properties' => [
            'id' => ['type' => 'string', 'description' => 'The install ID to poll.'],
            'status' => ['type' => 'string', 'enum' => ['queued', 'running', 'completed', 'failed']],
            'message' => ['type' => 'string', 'nullable' => true, 'description' => 'Why it failed, or null.'],
            'file_name' => ['type' => 'string', 'nullable' => true, 'description' => 'The jar installed, or the Forge/NeoForge version set, once completed.'],
            'directory' => ['type' => 'string'],
            'version_number' => ['type' => 'string', 'description' => 'The software and version, such as "Paper 1.21.4".'],
        ],
    ];

    private const array PROGRESS_EXAMPLE = [
        'object' => 'minecraft_version_install',
        'attributes' => ['id' => '9b2f3c4d-5e6f-4a1b-8c9d-0e1f2a3b4c5d', 'status' => 'queued', 'message' => null, 'file_name' => null, 'directory' => '', 'version_number' => 'Paper 1.21.4'],
    ];

    private const array NOT_MINECRAFT_ERROR = [
        'errors' => [['code' => 'MinecraftVersionException', 'status' => '400', 'detail' => 'This server does not run Minecraft Java.']],
    ];

    private const array API_ERROR = [
        'errors' => [['code' => 'MinecraftVersionException', 'status' => '502', 'detail' => 'PaperMC did not answer. Try again in a minute.']],
    ];

    public function __construct(
        private readonly MinecraftVersionCatalog $catalog,
        private readonly MinecraftVersionInstaller $installer,
        private readonly ServerPlatformDetector $detector,
    ) {}

    /**
     * @return array{object: string, attributes: array{current: array{software: string|null, minecraft_version: string|null}, software: list<array<string, mixed>>}}
     *
     * @throws MinecraftVersionException
     */
    #[Endpoint('Get Minecraft software', "Returns the software the server runs now, as the panel detects it, and the software it can switch to, with whether this server's egg allows each.")]
    #[ResponseField('object', 'string', required: true)]
    #[ResponseField('attributes.current.software', 'string', 'The detected software, or null when the panel cannot tell.', nullable: true)]
    #[ResponseField('attributes.current.minecraft_version', 'string', 'The detected Minecraft version, or null.', nullable: true)]
    #[ResponseField('attributes.software[].unavailable_reason', 'string', "Why this server's egg cannot switch to it, or null.", nullable: true)]
    #[ScribeResponse([
        'object' => 'minecraft_software',
        'attributes' => [
            'current' => ['software' => 'paper', 'minecraft_version' => '1.21.4'],
            'software' => [['id' => 'paper', 'label' => 'Paper', 'description' => 'Fast, plugin-ready server (Bukkit and Paper plugins).', 'has_snapshots' => false, 'needs_installer' => false, 'available' => true, 'unavailable_reason' => null]],
        ],
    ], description: 'Software returned.')]
    #[ScribeResponse(self::NOT_MINECRAFT_ERROR, status: 400, description: 'The server does not run Minecraft Java.')]
    public function index(ReadMinecraftVersionsRequest $request, Server $server): array
    {
        $this->assertMinecraft($server);
        $platform = $this->detector->detect($server);

        $software = [];
        foreach (MinecraftSoftware::cases() as $case) {
            $reason = null;
            try {
                $this->installer->assertInstallable($server, $case);
            } catch (MinecraftVersionException $exception) {
                $reason = $exception->getMessage();
            }

            $software[] = [
                'id' => $case->value,
                'label' => $case->label(),
                'description' => $case->description(),
                'has_snapshots' => $case->hasSnapshots(),
                'needs_installer' => $case->needsInstaller(),
                'available' => $reason === null,
                'unavailable_reason' => $reason,
            ];
        }

        return [
            'object' => 'minecraft_software',
            'attributes' => [
                'current' => ['software' => $platform->loader, 'minecraft_version' => $platform->gameVersion],
                'software' => $software,
            ],
        ];
    }

    /**
     * @return array{object: string, data: list<array{id: string, minecraft: string, type: string}>}
     *
     * @throws MinecraftVersionException
     */
    #[Endpoint('List Minecraft versions', "Lists a software's versions, newest first, from the project's own API (cached for an hour). Snapshots and pre-releases are left out unless asked for.")]
    #[UrlParam('software', 'string', 'The Minecraft server software: vanilla, paper, purpur, folia, fabric, forge or neoforge.', example: 'paper')]
    #[QueryParam('snapshots', 'boolean', 'Include snapshots and pre-releases (Vanilla, Fabric, and Paper release candidates).', required: false, example: false)]
    #[ResponseField('object', 'string', required: true)]
    #[ResponseField('data', 'object[]', 'The versions, newest first.', required: true, schema: self::VERSIONS_SCHEMA)]
    #[ScribeResponse(['object' => 'list', 'data' => [['id' => '1.21.4', 'minecraft' => '1.21.4', 'type' => 'release']]], description: 'Versions returned.')]
    #[ScribeResponse(self::API_ERROR, status: 502, description: 'The version list did not answer.')]
    public function versions(ReadMinecraftVersionsRequest $request, Server $server, string $software): array
    {
        $this->assertMinecraft($server);
        $case = MinecraftSoftware::tryFrom($software) ?? throw new NotFoundHttpException('That software is not supported.');

        return ['object' => 'list', 'data' => $this->catalog->versions($case, $request->boolean('snapshots'))];
    }

    /**
     * @throws MinecraftVersionException
     */
    #[Endpoint('Install Minecraft version', "Queues switching the server to a software and version and returns 202 with an install ID to poll. The server is stopped and its jar replaced (the world is never touched): the jar comes only from the project's own host (piston-data.mojang.com, fill-data.papermc.io, api.purpurmc.org or meta.fabricmc.net) and is checked against the sha1 or sha256 the API gives. Forge and NeoForge are installed by setting the egg's FORGE_VERSION or NEOFORGE_VERSION and reinstalling the server, which needs a Forge or NeoForge egg. Needs the versions.manage permission. Limited to a few installs a minute.")]
    #[BodyParam('software', 'string', 'vanilla, paper, purpur, folia, fabric, forge or neoforge.', required: true, example: 'paper')]
    #[BodyParam('version', 'string', 'A version id from the versions list.', required: true, example: '1.21.4')]
    #[ResponseField('object', 'string', required: true)]
    #[ResponseField('attributes', 'object', required: true, schema: self::PROGRESS_SCHEMA)]
    #[ScribeResponse(self::PROGRESS_EXAMPLE, status: 202, description: 'Install queued.')]
    #[ScribeResponse(self::NOT_MINECRAFT_ERROR, status: 400, description: 'The server does not run Minecraft Java, or its egg cannot switch to this software.')]
    public function install(InstallMinecraftVersionRequest $request, Server $server): JsonResponse
    {
        $this->assertMinecraft($server);
        $software = $request->software();
        $this->installer->assertInstallable($server, $software);

        $download = $this->catalog->resolve($software, $request->string('version')->toString());

        $progress = PluginInstallProgress::start($server, '', $software->label().' '.$download['version']);
        dispatch(new InstallMinecraftVersionJob($server->id, $request->user()->id, $progress['id'], $download));

        return new JsonResponse(['object' => 'minecraft_version_install', 'attributes' => $progress], JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * @return array{object: string, attributes: array<string, mixed>}
     */
    #[Endpoint('Get Minecraft version install progress', 'Returns a queued software switch: queued, running, completed or failed (with why). Kept for a day.')]
    #[UrlParam('installJob', 'string', 'The install ID the install returned.', example: '9b2f3c4d-5e6f-4a1b-8c9d-0e1f2a3b4c5d')]
    #[ResponseField('object', 'string', required: true)]
    #[ResponseField('attributes', 'object', required: true, schema: self::PROGRESS_SCHEMA)]
    #[ScribeResponse(['object' => 'minecraft_version_install', 'attributes' => ['id' => '9b2f3c4d-5e6f-4a1b-8c9d-0e1f2a3b4c5d', 'status' => 'completed', 'message' => null, 'file_name' => 'server.jar', 'directory' => '', 'version_number' => 'Paper 1.21.4']], description: 'Progress returned.')]
    #[ScribeResponse(['errors' => [['code' => 'NotFoundHttpException', 'status' => '404', 'detail' => 'That install was not found.']]], status: 404, description: 'No such install on this server.')]
    public function progress(ReadMinecraftVersionsRequest $request, Server $server, string $installJob): array
    {
        $progress = PluginInstallProgress::get($server, $installJob) ?? throw new NotFoundHttpException('That install was not found.');

        return ['object' => 'minecraft_version_install', 'attributes' => $progress];
    }

    /** @throws MinecraftVersionException */
    private function assertMinecraft(Server $server): void
    {
        throw_unless(GameResolver::isMinecraft($server), MinecraftVersionException::class, 'This server does not run Minecraft Java.');
    }
}
