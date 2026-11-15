<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client\Servers\Plugins;

use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Data\Plugins\MarketplaceFile;
use Pterodactyl\Data\Plugins\MarketplaceProject;
use Pterodactyl\Data\Plugins\MarketplaceSearch;
use Pterodactyl\Data\Plugins\MarketplaceVersion;
use Pterodactyl\Enum\MarketplaceProjectType;
use Pterodactyl\Enum\MarketplaceSource;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Plugins\MarketplaceException;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Plugins\InstalledPluginRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Plugins\InstallPluginRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Plugins\ListPluginVersionsRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Plugins\ReadPluginsRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Plugins\SearchPluginsRequest;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Plugins\DownloadPolicy;
use Pterodactyl\Services\Plugins\InstalledPlugins;
use Pterodactyl\Services\Plugins\MarketplaceCatalogs;
use Pterodactyl\Services\Plugins\PluginInstaller;
use Pterodactyl\Services\Plugins\ServerPlatform;
use Pterodactyl\Services\Plugins\ServerPlatformDetector;
use Pterodactyl\Services\Plugins\Sources\CurseForgeCatalog;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One-click plugins and mods: browse Modrinth, Hangar and (when an admin set a key) CurseForge
 * for the server's loader and Minecraft version, install a version's file into plugins/ or
 * mods/, and update or remove the installed jars. The panel resolves every download itself
 * from the catalog; the user only ever names a project and a version.
 */
#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Server Plugins', 'Browse, install, update and remove plugins, mods and Modrinth modpacks.')]
class PluginController extends ClientApiController
{
    private const array PLATFORM_EXAMPLE = [
        'loader' => 'paper',
        'game_version' => '1.21.4',
        'type' => 'plugin',
        'directory' => 'plugins',
        'detected_from' => ['files'],
    ];

    private const array PROJECT_EXAMPLE = [
        'source' => 'modrinth',
        'id' => 'P7dR8mSH',
        'slug' => 'luckperms',
        'title' => 'LuckPerms',
        'description' => 'A permissions plugin for Minecraft servers.',
        'author' => 'Luck',
        'icon_url' => 'https://cdn.modrinth.com/data/P7dR8mSH/icon.png',
        'downloads' => 1250000,
        'updated_at' => '2026-11-01T12:00:00Z',
        'url' => 'https://modrinth.com/plugin/luckperms',
        'client_only' => false,
    ];

    private const array VERSION_EXAMPLE = [
        'id' => 'OrIs0S6b',
        'project_id' => 'P7dR8mSH',
        'name' => 'LuckPerms 5.4.150',
        'version_number' => '5.4.150',
        'game_versions' => ['1.21.3', '1.21.4'],
        'loaders' => ['paper', 'spigot'],
        'channel' => 'release',
        'published_at' => '2026-11-01T12:00:00Z',
        'file' => [
            'filename' => 'LuckPerms-Bukkit-5.4.150.jar',
            'size' => 2097152,
            'hashes' => ['sha1' => 'a94a8fe5ccb19ba61c4c0873d391e987982fbbd3'],
        ],
        'installable' => true,
    ];

    private const array INSTALLED_EXAMPLE = [
        'file_name' => 'LuckPerms-Bukkit-5.4.140.jar',
        'size' => 2097152,
        'modified_at' => '2026-10-01T12:00:00Z',
        'sha1' => 'a94a8fe5ccb19ba61c4c0873d391e987982fbbd3',
        'project' => [
            'source' => 'modrinth',
            'id' => 'P7dR8mSH',
            'title' => 'LuckPerms',
            'slug' => 'luckperms',
            'icon_url' => 'https://cdn.modrinth.com/data/P7dR8mSH/icon.png',
            'url' => 'https://modrinth.com/plugin/luckperms',
        ],
        'version' => ['id' => 'Xy12Ab34', 'name' => 'LuckPerms 5.4.140', 'version_number' => '5.4.140'],
        'update' => ['id' => 'OrIs0S6b', 'name' => 'LuckPerms 5.4.150', 'version_number' => '5.4.150'],
    ];

    private const array INSTALL_RESULT_EXAMPLE = [
        'object' => 'plugin_install',
        'attributes' => ['file_name' => 'LuckPerms-Bukkit-5.4.150.jar', 'directory' => 'plugins', 'version_number' => '5.4.150'],
    ];

    private const array STRING = ['type' => 'string'];

    private const array NULLABLE_STRING = ['type' => 'string', 'nullable' => true];

    private const array STRINGS = ['type' => 'array', 'items' => ['type' => 'string']];

    private const array PROJECTS_SCHEMA = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['source', 'id', 'slug', 'title', 'description', 'author', 'icon_url', 'downloads', 'updated_at', 'url', 'client_only'],
            'properties' => [
                'source' => ['type' => 'string', 'enum' => ['modrinth', 'hangar', 'curseforge']],
                'id' => self::STRING,
                'slug' => self::STRING,
                'title' => self::STRING,
                'description' => self::STRING,
                'author' => self::NULLABLE_STRING,
                'icon_url' => self::NULLABLE_STRING,
                'downloads' => ['type' => 'integer'],
                'updated_at' => self::NULLABLE_STRING,
                'url' => self::STRING,
                'client_only' => ['type' => 'boolean', 'description' => 'Modrinth says the project does not run on servers.'],
            ],
        ],
    ];

    private const array VERSIONS_SCHEMA = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'project_id', 'name', 'version_number', 'game_versions', 'loaders', 'channel', 'published_at', 'file', 'installable'],
            'properties' => [
                'id' => self::STRING,
                'project_id' => self::STRING,
                'name' => self::STRING,
                'version_number' => self::STRING,
                'game_versions' => self::STRINGS,
                'loaders' => self::STRINGS,
                'channel' => ['type' => 'string', 'description' => 'release, beta or alpha (Hangar channels by name).'],
                'published_at' => self::NULLABLE_STRING,
                'file' => [
                    'type' => 'object',
                    'nullable' => true,
                    'required' => ['filename', 'size', 'hashes'],
                    'properties' => [
                        'filename' => self::STRING,
                        'size' => ['type' => 'integer'],
                        'hashes' => ['type' => 'object', 'description' => 'Hex digests by algorithm: sha1, sha256, sha512 or md5.', 'additionalProperties' => ['type' => 'string']],
                    ],
                ],
                'installable' => ['type' => 'boolean'],
            ],
        ],
    ];

    private const array VERSION_REF_SCHEMA = [
        'type' => 'object',
        'nullable' => true,
        'required' => ['id', 'name', 'version_number'],
        'properties' => ['id' => self::STRING, 'name' => self::STRING, 'version_number' => self::STRING],
    ];

    private const array INSTALLED_SCHEMA = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['file_name', 'size', 'modified_at', 'sha1', 'project', 'version', 'update'],
            'properties' => [
                'file_name' => self::STRING,
                'size' => ['type' => 'integer'],
                'modified_at' => self::NULLABLE_STRING,
                'sha1' => self::NULLABLE_STRING,
                'project' => [
                    'type' => 'object',
                    'nullable' => true,
                    'description' => 'The Modrinth project the jar belongs to, or null when Modrinth does not know it.',
                    'required' => ['source', 'id', 'title', 'slug', 'icon_url', 'url'],
                    'properties' => ['source' => self::STRING, 'id' => self::STRING, 'title' => self::STRING, 'slug' => self::STRING, 'icon_url' => self::NULLABLE_STRING, 'url' => self::STRING],
                ],
                'version' => self::VERSION_REF_SCHEMA,
                'update' => [...self::VERSION_REF_SCHEMA, 'description' => 'A newer version for the server, or null.'],
            ],
        ],
    ];

    private const array PAGINATION_SCHEMA = [
        'type' => 'object',
        'required' => ['pagination'],
        'properties' => [
            'pagination' => [
                'type' => 'object',
                'required' => ['total', 'count', 'per_page', 'current_page', 'total_pages'],
                'properties' => [
                    'total' => ['type' => 'integer'],
                    'count' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                    'current_page' => ['type' => 'integer'],
                    'total_pages' => ['type' => 'integer'],
                ],
            ],
        ],
    ];

    private const array DAEMON_ERROR = [
        'errors' => [['code' => 'DaemonConnectionException', 'status' => '502', 'detail' => 'There was an error while communicating with the machine running this server.']],
    ];

    private const array CATALOG_ERROR = [
        'errors' => [['code' => 'MarketplaceException', 'status' => '502', 'detail' => 'Modrinth did not answer. Try again in a minute.']],
    ];

    private const array INSTALL_ERROR = [
        'errors' => [['code' => 'MarketplaceException', 'status' => '400', 'detail' => "LuckPerms-Bukkit-5.4.150.jar did not match the catalog's sha1 hash, so it was removed."]],
    ];

    public function __construct(
        private readonly ServerPlatformDetector $detector,
        private readonly MarketplaceCatalogs $catalogs,
    ) {}

    /**
     * @return array{object: string, attributes: array{platform: array{loader: string|null, game_version: string|null, type: string|null, directory: string|null, detected_from: list<string>}, detected: array{loader: string|null, game_version: string|null, type: string|null, directory: string|null, detected_from: list<string>}, sources: array<string, list<string>>, loaders: list<string>, curseforge_enabled: bool}}
     */
    #[Endpoint('Get plugin environment', "Returns the server's detected loader and Minecraft version (from its files, egg and startup variables), the values used after the loader and game_version overrides, and the catalogs that can be searched for each project type.")]
    #[QueryParam('loader', 'string', 'Use this loader instead of the detected one.', required: false, example: 'paper')]
    #[QueryParam('game_version', 'string', 'Use this Minecraft version instead of the detected one.', required: false, example: '1.21.4')]
    #[ResponseField('attributes.platform.loader', 'string', nullable: true)]
    #[ResponseField('attributes.platform.game_version', 'string', nullable: true)]
    #[ResponseField('attributes.platform.type', 'string', nullable: true)]
    #[ResponseField('attributes.platform.directory', 'string', nullable: true)]
    #[ResponseField('attributes.detected.loader', 'string', nullable: true)]
    #[ResponseField('attributes.detected.game_version', 'string', nullable: true)]
    #[ResponseField('attributes.detected.type', 'string', nullable: true)]
    #[ResponseField('attributes.detected.directory', 'string', nullable: true)]
    #[ResponseField('attributes.sources', 'object', 'Searchable catalogs by project type.', schema: ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]])]
    #[ScribeResponse([
        'object' => 'plugin_environment',
        'attributes' => [
            'platform' => self::PLATFORM_EXAMPLE,
            'detected' => self::PLATFORM_EXAMPLE,
            'sources' => ['plugin' => ['modrinth', 'hangar'], 'mod' => ['modrinth'], 'modpack' => ['modrinth']],
            'loaders' => ['paper', 'purpur', 'folia', 'spigot', 'bukkit', 'velocity', 'waterfall', 'bungeecord', 'fabric', 'quilt', 'forge', 'neoforge'],
            'curseforge_enabled' => false,
        ],
    ], description: 'Plugin environment returned.')]
    public function environment(ReadPluginsRequest $request, Server $server): array
    {
        $detected = $this->detector->detect($server);
        $platform = $detected->withOverrides($request->loaderOverride(), $request->gameVersionOverride());

        return [
            'object' => 'plugin_environment',
            'attributes' => [
                'platform' => $platform->toArray(),
                'detected' => $detected->toArray(),
                'sources' => $this->catalogs->available($platform),
                'loaders' => ServerPlatform::loaders(),
                'curseforge_enabled' => CurseForgeCatalog::apiKey() !== '',
            ],
        ];
    }

    /**
     * @return array{object: string, data: list<array<string, mixed>>, meta: array{pagination: array{total: int, count: int, per_page: int, current_page: int, total_pages: int}}}
     *
     * @throws MarketplaceException
     */
    #[Endpoint('Search plugins', "Searches a catalog for plugins, mods or modpacks that suit the server's loader and Minecraft version. Results are cached for ten minutes.")]
    #[QueryParam('source', 'string', 'The catalog: modrinth, hangar or curseforge.', required: true, example: 'modrinth')]
    #[QueryParam('type', 'string', "plugin, mod or modpack. Defaults to what the server's loader runs.", required: false, example: 'plugin')]
    #[QueryParam('query', 'string', 'Search text.', required: false, example: 'permissions')]
    #[QueryParam('page', 'integer', 'Result page, 20 per page.', required: false, example: 1)]
    #[QueryParam('sort', 'string', 'relevance, downloads, updated or newest.', required: false, example: 'relevance')]
    #[QueryParam('loader', 'string', 'Use this loader instead of the detected one.', required: false, example: 'paper')]
    #[QueryParam('game_version', 'string', 'Use this Minecraft version instead of the detected one.', required: false, example: '1.21.4')]
    #[ResponseField('object', 'string', required: true)]
    #[ResponseField('data', 'object[]', 'The projects found.', required: true, schema: self::PROJECTS_SCHEMA)]
    #[ResponseField('meta', 'object', required: true, schema: self::PAGINATION_SCHEMA)]
    #[ScribeResponse([
        'object' => 'list',
        'data' => [self::PROJECT_EXAMPLE],
        'meta' => ['pagination' => ['total' => 1, 'count' => 1, 'per_page' => 20, 'current_page' => 1, 'total_pages' => 1]],
    ], description: 'Search results returned.')]
    #[ScribeResponse(self::CATALOG_ERROR, status: 502, description: 'The catalog did not answer.')]
    public function search(SearchPluginsRequest $request, Server $server): array
    {
        $platform = $this->platform($request, $server);
        $type = $this->type($request->input('type'), $platform);
        $source = MarketplaceSource::from($request->string('source')->toString());
        $catalog = $this->catalogs->for($source, $type, $platform);

        $result = $catalog->search(new MarketplaceSearch(
            query: $request->string('query')->toString(),
            type: $type,
            platform: $platform,
            page: $request->integer('page', 1),
            sort: $request->string('sort', 'relevance')->toString(),
        ));

        return [
            'object' => 'list',
            'data' => array_map(fn (MarketplaceProject $project): array => $project->toArray(), $result->projects),
            'meta' => ['pagination' => [
                'total' => $result->total,
                'count' => count($result->projects),
                'per_page' => $result->perPage,
                'current_page' => $result->page,
                'total_pages' => max(1, (int) ceil($result->total / $result->perPage)),
            ]],
        ];
    }

    /**
     * @return array{object: string, data: list<array<string, mixed>>}
     *
     * @throws MarketplaceException
     */
    #[Endpoint('List plugin versions', "Lists a project's versions that suit the server's loader and Minecraft version, newest first, with the file each would install. Modpack versions are not filtered, so their loaders and game versions can be compared with the server.")]
    #[QueryParam('source', 'string', 'The catalog: modrinth, hangar or curseforge.', required: true, example: 'modrinth')]
    #[QueryParam('project', 'string', "The project's ID from the search results.", required: true, example: 'P7dR8mSH')]
    #[QueryParam('type', 'string', "plugin, mod or modpack. Defaults to what the server's loader runs.", required: false, example: 'plugin')]
    #[QueryParam('loader', 'string', 'Use this loader instead of the detected one.', required: false, example: 'paper')]
    #[QueryParam('game_version', 'string', 'Use this Minecraft version instead of the detected one.', required: false, example: '1.21.4')]
    #[ResponseField('object', 'string', required: true)]
    #[ResponseField('data', 'object[]', 'The versions, newest first.', required: true, schema: self::VERSIONS_SCHEMA)]
    #[ScribeResponse(['object' => 'list', 'data' => [self::VERSION_EXAMPLE]], description: 'Versions returned.')]
    #[ScribeResponse(self::CATALOG_ERROR, status: 502, description: 'The catalog did not answer.')]
    public function versions(ListPluginVersionsRequest $request, Server $server): array
    {
        $platform = $this->platform($request, $server);
        $type = $this->type($request->input('type'), $platform);
        $source = MarketplaceSource::from($request->string('source')->toString());
        $catalog = $this->catalogs->for($source, $type, $platform);

        $versions = $catalog->versions($request->string('project')->toString(), $type, $platform);

        return [
            'object' => 'list',
            'data' => array_map(fn (MarketplaceVersion $version): array => $version->toArray(), $versions),
        ];
    }

    /**
     * @return array{object: string, attributes: array{file_name: string, directory: string, version_number: string}}
     *
     * @throws MarketplaceException
     * @throws DaemonConnectionException
     */
    #[Endpoint('Install plugin', "Installs a plugin or mod version into plugins/ or mods/. The panel looks the version up in the catalog itself, has Wings download the file from the catalog's CDN (only cdn.modrinth.com, hangarcdn.papermc.io, mediafilez.forgecdn.net and edge.forgecdn.net are allowed), checks its hashes and only then puts it in place. Needs the plugins.manage permission.")]
    #[BodyParam('source', 'string', 'The catalog: modrinth, hangar or curseforge.', required: true, example: 'modrinth')]
    #[BodyParam('project', 'string', "The project's ID.", required: true, example: 'P7dR8mSH')]
    #[BodyParam('version', 'string', "The version's ID from the versions list.", required: true, example: 'OrIs0S6b')]
    #[BodyParam('loader', 'string', 'Use this loader instead of the detected one.', required: false, example: 'paper', nullable: true)]
    #[BodyParam('game_version', 'string', 'Use this Minecraft version instead of the detected one.', required: false, example: '1.21.4', nullable: true)]
    #[ScribeResponse(self::INSTALL_RESULT_EXAMPLE, description: 'Plugin installed.')]
    #[ScribeResponse(self::INSTALL_ERROR, status: 400, description: 'The file could not be installed: not allowed, no file, or a failed hash check.')]
    #[ScribeResponse(self::DAEMON_ERROR, status: 502, description: 'Wings could not download the file.')]
    public function install(InstallPluginRequest $request, PluginInstaller $installer, Server $server): array
    {
        $platform = $this->platform($request, $server);
        $type = $this->type(null, $platform);
        $directory = $platform->directory() ?? throw new MarketplaceException("Choose the server's loader first.");
        $source = MarketplaceSource::from($request->string('source')->toString());
        $catalog = $this->catalogs->for($source, $type, $platform);

        $version = $catalog->version($request->string('project')->toString(), $request->string('version')->toString(), $type);
        throw_unless($version instanceof MarketplaceVersion, MarketplaceException::class, 'That version was not found in the catalog.');

        throw_if(! $version->file instanceof MarketplaceFile, MarketplaceException::class, 'This version cannot be installed from the panel: its author does not allow downloads from other apps, or it has no jar.');

        $name = $installer->install($server, $version->file, $directory);

        Activity::event('server:plugin.install')->subject($server)->property([
            'file' => $directory.'/'.$name,
            'version' => $version->versionNumber,
            'source' => $source->label(),
        ])->log();

        return [
            'object' => 'plugin_install',
            'attributes' => ['file_name' => $name, 'directory' => $directory, 'version_number' => $version->versionNumber],
        ];
    }

    /**
     * @return array{object: string, data: list<array<string, mixed>>, meta: array{directory: string|null}}
     *
     * @throws MarketplaceException
     * @throws DaemonConnectionException
     */
    #[Endpoint('List installed plugins', "Lists the jars in the server's plugins/ or mods/ folder. Each is matched to its Modrinth project by sha1, with the newest version for the server's platform when there is a newer one.")]
    #[QueryParam('loader', 'string', 'Use this loader instead of the detected one.', required: false, example: 'paper')]
    #[QueryParam('game_version', 'string', 'Use this Minecraft version instead of the detected one.', required: false, example: '1.21.4')]
    #[ResponseField('object', 'string', required: true)]
    #[ResponseField('data', 'object[]', 'The jars, by name.', required: true, schema: self::INSTALLED_SCHEMA)]
    #[ResponseField('meta', 'object', required: true, schema: ['type' => 'object', 'required' => ['directory'], 'properties' => ['directory' => ['type' => 'string', 'nullable' => true, 'description' => 'plugins or mods, or null while the loader is unknown.']]])]
    #[ScribeResponse(['object' => 'list', 'data' => [self::INSTALLED_EXAMPLE], 'meta' => ['directory' => 'plugins']], description: 'Installed plugins returned.')]
    #[ScribeResponse(self::DAEMON_ERROR, status: 502, description: 'Wings could not list the folder.')]
    public function installed(ReadPluginsRequest $request, InstalledPlugins $installed, Server $server): array
    {
        $platform = $this->platform($request, $server);

        return [
            'object' => 'list',
            'data' => $installed->list($server, $platform),
            'meta' => ['directory' => $platform->directory()],
        ];
    }

    /**
     * @return array{object: string, attributes: array{file_name: string, directory: string, version_number: string}}
     *
     * @throws MarketplaceException
     * @throws DaemonConnectionException
     */
    #[Endpoint('Update installed plugin', 'Replaces an installed jar with the newest Modrinth version for the server: the new file is downloaded and checked first, then the old one is removed. Needs the plugins.manage permission.')]
    #[BodyParam('file', 'string', "The installed jar's file name.", required: true, example: 'LuckPerms-Bukkit-5.4.140.jar')]
    #[BodyParam('loader', 'string', 'Use this loader instead of the detected one.', required: false, example: 'paper', nullable: true)]
    #[BodyParam('game_version', 'string', 'Use this Minecraft version instead of the detected one.', required: false, example: '1.21.4', nullable: true)]
    #[ScribeResponse(self::INSTALL_RESULT_EXAMPLE, description: 'Plugin updated.')]
    #[ScribeResponse(self::INSTALL_ERROR, status: 400, description: 'There is no update, or the new file could not be installed.')]
    #[ScribeResponse(self::DAEMON_ERROR, status: 502, description: 'Wings could not download the file.')]
    public function update(InstalledPluginRequest $request, InstalledPlugins $installed, PluginInstaller $installer, Server $server): array
    {
        $platform = $this->platform($request, $server);
        $directory = $platform->directory() ?? throw new MarketplaceException("Choose the server's loader first.");
        $file = $this->jarName($request);

        $jar = $installed->find($server, $platform, $file) ?? throw new NotFoundHttpException('That file is not installed.');
        throw_if($jar['project'] === null || $jar['update'] === null, MarketplaceException::class, 'There is no update for this file.');

        $version = $this->catalogs->modrinth()->version($jar['project']['id'], $jar['update']['id'], MarketplaceProjectType::Mod);
        throw_if(! $version instanceof MarketplaceVersion || ! $version->file instanceof MarketplaceFile, MarketplaceException::class, 'The update was not found in the catalog.');

        $name = $installer->install($server, $version->file, $directory, replaces: $file);

        Activity::event('server:plugin.update')->subject($server)->property([
            'old' => $directory.'/'.$file,
            'file' => $directory.'/'.$name,
            'version' => $version->versionNumber,
        ])->log();

        return [
            'object' => 'plugin_install',
            'attributes' => ['file_name' => $name, 'directory' => $directory, 'version_number' => $version->versionNumber],
        ];
    }

    /**
     * @throws MarketplaceException
     * @throws DaemonConnectionException
     */
    #[Endpoint('Remove installed plugin', "Deletes a jar from the server's plugins/ or mods/ folder. Needs the plugins.manage permission.")]
    #[BodyParam('file', 'string', "The installed jar's file name.", required: true, example: 'LuckPerms-Bukkit-5.4.140.jar')]
    #[BodyParam('loader', 'string', 'Use this loader instead of the detected one.', required: false, example: 'paper', nullable: true)]
    #[BodyParam('game_version', 'string', 'Use this Minecraft version instead of the detected one.', required: false, example: '1.21.4', nullable: true)]
    #[ScribeResponse(status: 204, description: 'Plugin removed.')]
    #[ScribeResponse(self::DAEMON_ERROR, status: 502, description: 'Wings could not delete the file.')]
    public function remove(InstalledPluginRequest $request, PluginInstaller $installer, Server $server): Response
    {
        $platform = $this->platform($request, $server);
        $directory = $platform->directory() ?? throw new MarketplaceException("Choose the server's loader first.");
        $file = $this->jarName($request);

        throw_unless(in_array($file, $installer->jarNames($server, $directory), true), NotFoundHttpException::class, 'That file is not installed.');

        $installer->remove($server, $directory, $file);

        Activity::event('server:plugin.remove')->subject($server)->property(['file' => $directory.'/'.$file])->log();

        return $this->returnNoContent();
    }

    private function platform(ReadPluginsRequest $request, Server $server): ServerPlatform
    {
        return $this->detector->detect($server)->withOverrides($request->loaderOverride(), $request->gameVersionOverride());
    }

    /** @throws MarketplaceException */
    private function type(mixed $requested, ServerPlatform $platform): MarketplaceProjectType
    {
        $type = is_string($requested) && $requested !== '' ? MarketplaceProjectType::from($requested) : $platform->type();

        return $type ?? throw new MarketplaceException('The panel could not tell which loader this server runs. Choose it to see plugins and mods.');
    }

    /** @throws MarketplaceException */
    private function jarName(InstalledPluginRequest $request): string
    {
        return DownloadPolicy::jarName($request->string('file')->toString())
            ?? throw new MarketplaceException('That is not a plugin or mod jar.');
    }
}
