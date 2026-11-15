<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Plugins;

use Pterodactyl\Enum\MarketplaceProjectType;

/**
 * The Minecraft platform a server runs: its loader (paper, fabric...) and game version,
 * either detected (see ServerPlatformDetector) or chosen by the user.
 */
final readonly class ServerPlatform
{
    /** Loaders that run plugins from plugins/. */
    public const array PLUGIN_LOADERS = ['paper', 'purpur', 'folia', 'spigot', 'bukkit', 'velocity', 'waterfall', 'bungeecord'];

    /** Loaders that run mods from mods/. */
    public const array MOD_LOADERS = ['fabric', 'quilt', 'forge', 'neoforge'];

    /** @param  list<string>  $detectedFrom  where the values came from: egg, files, variable, override */
    public function __construct(
        public ?string $loader,
        public ?string $gameVersion,
        public array $detectedFrom = [],
    ) {}

    /** @return list<string> */
    public static function loaders(): array
    {
        return [...self::PLUGIN_LOADERS, ...self::MOD_LOADERS];
    }

    public static function validGameVersion(string $version): bool
    {
        return preg_match('/^\d{1,2}\.\d{1,2}(\.\d{1,2})?(-(pre|rc)\d{1,2})?$/', $version) === 1;
    }

    /** This platform with the user's choices applied over what was detected. */
    public function withOverrides(?string $loader, ?string $gameVersion): self
    {
        $from = $this->detectedFrom;
        if (($loader !== null && $loader !== $this->loader) || ($gameVersion !== null && $gameVersion !== $this->gameVersion)) {
            $from[] = 'override';
        }

        return new self($loader ?? $this->loader, $gameVersion ?? $this->gameVersion, array_values(array_unique($from)));
    }

    /** Whether the server takes plugins or mods, or null when the loader is unknown. */
    public function type(): ?MarketplaceProjectType
    {
        return match (true) {
            in_array($this->loader, self::PLUGIN_LOADERS, true) => MarketplaceProjectType::Plugin,
            in_array($this->loader, self::MOD_LOADERS, true) => MarketplaceProjectType::Mod,
            default => null,
        };
    }

    /** The folder jars are installed into, relative to the server root. */
    public function directory(): ?string
    {
        return match ($this->type()) {
            MarketplaceProjectType::Plugin => 'plugins',
            MarketplaceProjectType::Mod => 'mods',
            default => null,
        };
    }

    /**
     * The catalog loaders whose files run on this server: a Purpur server runs Paper,
     * Spigot and Bukkit plugins, a Quilt server runs Fabric mods.
     *
     * @return list<string>
     */
    public function compatibleLoaders(): array
    {
        return match ($this->loader) {
            'purpur' => ['purpur', 'paper', 'spigot', 'bukkit'],
            'paper' => ['paper', 'spigot', 'bukkit'],
            'spigot', 'bukkit' => ['spigot', 'bukkit'],
            'waterfall' => ['waterfall', 'bungeecord'],
            'quilt' => ['quilt', 'fabric'],
            null => [],
            default => [$this->loader],
        };
    }

    /** Hangar's platform for this server, or null when Hangar has nothing for it. */
    public function hangarPlatform(): ?string
    {
        return match ($this->loader) {
            'paper', 'purpur', 'folia' => 'PAPER',
            'velocity' => 'VELOCITY',
            'waterfall' => 'WATERFALL',
            default => null,
        };
    }

    /** CurseForge's ModLoaderType for this server's loader (Forge 1, Fabric 4, Quilt 5, NeoForge 6). */
    public function curseForgeLoader(): ?int
    {
        return match ($this->loader) {
            'forge' => 1,
            'fabric' => 4,
            'quilt' => 5,
            'neoforge' => 6,
            default => null,
        };
    }

    /** Whether CurseForge has anything for this server: mods, or Bukkit plugins. */
    public function curseForgeSupported(): bool
    {
        return $this->curseForgeLoader() !== null || in_array($this->loader, ['paper', 'purpur', 'spigot', 'bukkit'], true);
    }

    /**
     * @return array{loader: string|null, game_version: string|null, type: string|null, directory: string|null, detected_from: list<string>}
     */
    public function toArray(): array
    {
        return [
            'loader' => $this->loader,
            'game_version' => $this->gameVersion,
            'type' => $this->type()?->value,
            'directory' => $this->directory(),
            'detected_from' => $this->detectedFrom,
        ];
    }
}
