<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client\Servers\Plugins;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Data\Plugins\MarketplaceFile;
use Pterodactyl\Data\Plugins\MarketplaceVersion;
use Pterodactyl\Enum\MarketplaceProjectType;
use Pterodactyl\Exceptions\Plugins\MarketplaceException;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Plugins\InstallModpackRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Plugins\ReadPluginsRequest;
use Pterodactyl\Jobs\Plugins\InstallModpackJob;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Plugins\MarketplaceCatalogs;
use Pterodactyl\Services\Plugins\ModpackInstaller;
use Pterodactyl\Services\Plugins\ModpackProgress;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Modrinth modpacks: start an install, which runs in the background (InstallModpackJob), and
 * read its progress.
 */
#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Server Plugins', 'Browse, install, update and remove plugins, mods and Modrinth modpacks.')]
class ModpackController extends ClientApiController
{
    private const array PROGRESS_EXAMPLE = [
        'object' => 'modpack_install',
        'attributes' => [
            'status' => 'running',
            'step' => 'files',
            'message' => null,
            'project_title' => 'Fabulously Optimized',
            'version_name' => '6.2.0',
            'files_done' => 12,
            'files_total' => 40,
            'backup' => 'created',
            'started_at' => '2026-11-15T12:00:00+00:00',
            'finished_at' => null,
        ],
    ];

    private const array CONFLICT_ERROR = [
        'errors' => [['code' => 'ConflictHttpException', 'status' => '409', 'detail' => 'A modpack install is already running on this server.']],
    ];

    private const array CATALOG_ERROR = [
        'errors' => [['code' => 'MarketplaceException', 'status' => '400', 'detail' => 'That modpack version was not found on Modrinth.']],
    ];

    /**
     * @return array{object: string, attributes: array<string, mixed>}
     */
    #[Endpoint('Get modpack install progress', "Returns the server's latest modpack install: idle when there has been none in the last day, otherwise queued, running, completed or failed, with the current step (backup, download, extract, files, overrides, install, cleanup, done) and the files downloaded so far.")]
    #[ResponseField('attributes.message', 'string', nullable: true)]
    #[ResponseField('attributes.backup', 'string', 'created, skipped, or null before the backup step.', nullable: true)]
    #[ResponseField('attributes.finished_at', 'string', nullable: true)]
    #[ScribeResponse(self::PROGRESS_EXAMPLE, description: 'Progress returned.')]
    public function progress(ReadPluginsRequest $request, Server $server): array
    {
        return ['object' => 'modpack_install', 'attributes' => ModpackProgress::get($server) ?? [
            'status' => 'idle',
            'step' => 'idle',
            'message' => null,
            'project_title' => '',
            'version_name' => '',
            'files_done' => 0,
            'files_total' => 0,
            'backup' => null,
            'started_at' => '',
            'finished_at' => null,
        ]];
    }

    /**
     * @throws MarketplaceException
     * @throws Throwable
     */
    #[Endpoint('Install modpack', 'Starts installing a Modrinth modpack version. The panel backs the server up first; without a free backup slot the install is refused unless confirm_without_backup is true. It downloads the .mrpack and every server file from allowed hosts with hash checks, and only then moves them into place with overrides/ and server-overrides/, refusing before any change when a target differs in type from what is there or would replace something in a world or plugin data folder. Folders are never deleted. It runs in the background; poll the progress endpoint. Needs the plugins.manage permission.')]
    #[BodyParam('project', 'string', "The modpack's Modrinth project ID.", required: true, example: '1KVo5zza')]
    #[BodyParam('version', 'string', "The modpack version's ID.", required: true, example: 'yKXF4LMk')]
    #[BodyParam('confirm_without_backup', 'boolean', 'Install even though no backup can be made first (the server has no free backup slot). Without it such an install is refused.', required: false, example: false)]
    #[ResponseField('attributes.message', 'string', nullable: true)]
    #[ResponseField('attributes.backup', 'string', nullable: true)]
    #[ResponseField('attributes.finished_at', 'string', nullable: true)]
    #[ScribeResponse(self::PROGRESS_EXAMPLE, status: 202, description: 'Install started.')]
    #[ScribeResponse(self::CATALOG_ERROR, status: 400, description: 'The version is not a modpack the panel can install.')]
    #[ScribeResponse(self::CONFLICT_ERROR, status: 409, description: 'Another modpack install is running.')]
    public function store(InstallModpackRequest $request, MarketplaceCatalogs $catalogs, Server $server): JsonResponse
    {
        $modrinth = $catalogs->modrinth();
        $project = $request->string('project')->toString();
        $version = $modrinth->version($project, $request->string('version')->toString(), MarketplaceProjectType::Modpack);
        throw_if(! $version instanceof MarketplaceVersion || ! $version->file instanceof MarketplaceFile, MarketplaceException::class, 'That modpack version was not found on Modrinth.');

        $title = $modrinth->projects([$project])[$project]['title'] ?? $project;

        $confirmed = $request->boolean('confirm_without_backup');
        throw_if(! $confirmed && ! ModpackInstaller::canBackup($server), MarketplaceException::class, ModpackInstaller::NO_BACKUP_MESSAGE);

        $token = ModpackProgress::claim($server) ?? throw new ConflictHttpException('A modpack install is already running on this server.');

        try {
            ModpackProgress::start($server, $title, $version->versionNumber);

            Activity::event('server:modpack.install')->subject($server)->property(['name' => $title, 'version' => $version->versionNumber])->log();

            dispatch(new InstallModpackJob($server->id, $request->user()->id, $version->file, $title, $version->versionNumber, $token, $confirmed));
        } catch (Throwable $throwable) {
            ModpackProgress::release($server, $token);

            throw $throwable;
        }

        return new JsonResponse(['object' => 'modpack_install', 'attributes' => ModpackProgress::get($server)], JsonResponse::HTTP_ACCEPTED);
    }
}
