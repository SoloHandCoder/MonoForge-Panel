<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Games\Minecraft;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Pterodactyl\Contracts\Servers\ReinstallsServers;
use Pterodactyl\Contracts\Servers\UpdatesServerVariable;
use Pterodactyl\Exceptions\Games\MinecraftVersionException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Plugins\DownloadPolicy;
use Pterodactyl\Services\Plugins\ServerPlatformDetector;
use Pterodactyl\Services\Plugins\VerifiedDownload;
use Throwable;

/**
 * Switches a Minecraft server to another software and version without touching its world.
 *
 * Jar software (Vanilla, Paper, Purpur, Folia, Fabric): the server is stopped, Wings downloads
 * the jar from the project's own host under a temporary name, its sha1 or sha256 is checked
 * when the API gives one, and only then is it renamed over the server jar that the
 * `SERVER_JARFILE` startup variable names. A `unix_args.txt` left by Forge is renamed aside so
 * the Forge egg's startup boots the jar again.
 *
 * Forge and NeoForge need their installer to run, which only the egg's install script can do
 * (it has Java and runs before the server). So the server's egg must be a Forge or NeoForge egg
 * (it declares `FORGE_VERSION` or `NEOFORGE_VERSION`): the panel sets that variable to the
 * chosen version and reinstalls the server. The stock install scripts only replace the loader
 * files; the world and configs stay.
 */
final readonly class MinecraftVersionInstaller
{
    public const string JAR_VARIABLE = 'SERVER_JARFILE';

    /** Where a Forge launch file goes when the server switches back to a plain jar. */
    public const string FORGE_ARGS = 'unix_args.txt';

    public function __construct(
        private VerifiedDownload $downloads,
        private UpdatesServerVariable $variables,
        private ReinstallsServers $reinstalls,
        private ServerPlatformDetector $detector,
    ) {}

    /**
     * Checks before queueing that the server can take this software, so the user hears it at once.
     *
     * @throws MinecraftVersionException
     */
    public function assertInstallable(Server $server, MinecraftSoftware $software): void
    {
        $variable = $software->needsInstaller() ? $software->installerVariable() : self::JAR_VARIABLE;
        if ($variable !== null && ! $this->variable($server, $variable) instanceof EggVariable) {
            throw new MinecraftVersionException($software->needsInstaller()
                ? "{$software->label()} needs its installer to run when the server installs. Ask an administrator to switch this server to a {$software->label()} egg, then pick the version here."
                : "This server's egg does not choose its jar with the SERVER_JARFILE variable, so the panel cannot switch it.");
        }

        if ($software->needsInstaller()) {
            throw_unless($server->canBeReinstalled(), MinecraftVersionException::class, 'This server cannot be reinstalled right now.');
        }
    }

    /**
     * @param  array{software: string, version: string, minecraft: string, url: string|null, filename: string|null, hashes: array<string, string>}  $download
     * @return string what was installed: the jar name, or the installer version
     *
     * @throws MinecraftVersionException
     * @throws DaemonConnectionException
     */
    public function install(Server $server, MinecraftSoftware $software, array $download): string
    {
        $this->assertInstallable($server, $software);

        try {
            return $software->needsInstaller()
                ? $this->installWithInstaller($server, $software, $download['version'])
                : $this->installJar($server, $download);
        } finally {
            $this->detector->forget($server);
        }
    }

    /**
     * @param  array{software: string, version: string, minecraft: string, url: string|null, filename: string|null, hashes: array<string, string>}  $download
     *
     * @throws MinecraftVersionException
     * @throws DaemonConnectionException
     */
    private function installJar(Server $server, array $download): string
    {
        $url = $download['url'];
        throw_if($url === null || ! MinecraftVersionCatalog::allowedUrl($url), MinecraftVersionException::class, 'The download is not on an allowed site, so it was not installed.');

        $variable = $this->variable($server, self::JAR_VARIABLE) ?? throw new MinecraftVersionException('This server has no SERVER_JARFILE variable.');
        $current = mb_trim((string) ($variable->server_value ?? $variable->default_value));
        $jar = $current !== '' && DownloadPolicy::jarName($current) !== null ? $current : 'server.jar';

        $this->stop($server);

        $files = Daemon::server($server)->files();
        $temporary = '.monoforge-version-'.Str::lower(Str::random(16)).'.part';
        $this->downloads->download($server, $url, '', $temporary, $download['hashes'], label: $jar, hosts: MinecraftVersionCatalog::DOWNLOAD_HOSTS);

        try {
            $names = $this->rootNames($server);
            if (in_array($jar, $names, true)) {
                $files->deleteFiles('/', [$jar]);
            }

            $files->renameFiles('/', [['from' => $temporary, 'to' => $jar]]);

            if (in_array(self::FORGE_ARGS, $names, true)) {
                $aside = self::FORGE_ARGS.'.monoforge-old';
                if (in_array($aside, $names, true)) {
                    $files->deleteFiles('/', [$aside]);
                }

                $files->renameFiles('/', [['from' => self::FORGE_ARGS, 'to' => $aside]]);
            }
        } catch (Throwable $throwable) {
            $this->downloads->discard($server, '/', $temporary);

            throw $throwable;
        }

        if ($variable->server_value !== $jar && $variable->user_editable) {
            $this->variables->update($server, $variable->id, $jar);
        }

        return $jar;
    }

    /**
     * @throws MinecraftVersionException
     * @throws DaemonConnectionException
     */
    private function installWithInstaller(Server $server, MinecraftSoftware $software, string $version): string
    {
        $name = (string) $software->installerVariable();
        $variable = $this->variable($server, $name) ?? throw new MinecraftVersionException("This server has no {$name} variable.");

        $rules = is_string($variable->rules) && $variable->rules !== '' ? explode('|', $variable->rules) : ['nullable', 'string'];
        $validator = Validator::make(['value' => $version], ['value' => $rules]);
        throw_if($validator->fails(), MinecraftVersionException::class, "This server's egg does not accept {$software->label()} {$version} in {$name}. Ask an administrator to update the egg.");

        $this->variables->update($server, $variable->id, $version);
        $this->reinstalls->reinstall($server);

        return $version;
    }

    /**
     * Stops the server so the jar is not replaced under it. A server that is already off answers
     * the same.
     */
    private function stop(Server $server): void
    {
        try {
            Daemon::server($server)->power('stop');
        } catch (DaemonConnectionException) {
            // Offline or already stopped; the jar is swapped by rename either way.
        }
    }

    /**
     * @return list<string>
     *
     * @throws DaemonConnectionException
     */
    private function rootNames(Server $server): array
    {
        $names = [];
        foreach (Daemon::server($server)->files()->getDirectory('/') as $entry) {
            $name = $entry['name'] ?? null;
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function variable(Server $server, string $env): ?EggVariable
    {
        return $server->resolvedVariables()->first(fn (EggVariable $variable): bool => $variable->env_variable === $env);
    }
}
