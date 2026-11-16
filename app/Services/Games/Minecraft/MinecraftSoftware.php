<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Games\Minecraft;

/**
 * The Minecraft server software the Versions page can install.
 */
enum MinecraftSoftware: string
{
    case Vanilla = 'vanilla';
    case Paper = 'paper';
    case Purpur = 'purpur';
    case Folia = 'folia';
    case Fabric = 'fabric';
    case Forge = 'forge';
    case NeoForge = 'neoforge';

    public function label(): string
    {
        return match ($this) {
            self::Vanilla => 'Vanilla',
            self::Paper => 'Paper',
            self::Purpur => 'Purpur',
            self::Folia => 'Folia',
            self::Fabric => 'Fabric',
            self::Forge => 'Forge',
            self::NeoForge => 'NeoForge',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Vanilla => "Mojang's own server. No plugins or mods.",
            self::Paper => 'Fast, plugin-ready server (Bukkit and Paper plugins).',
            self::Purpur => 'Paper with extra gameplay options.',
            self::Folia => "Paper's multithreaded fork for very large servers; most plugins do not support it.",
            self::Fabric => 'Lightweight mod loader.',
            self::Forge => 'The classic mod loader. Installed by reinstalling the server with the Forge installer.',
            self::NeoForge => 'The modern Forge fork. Installed by reinstalling the server with the NeoForge installer.',
        };
    }

    /** Whether the list has snapshots to toggle. */
    public function hasSnapshots(): bool
    {
        return $this === self::Vanilla || $this === self::Fabric;
    }

    /** Forge and NeoForge need their installer run, which the egg's install script does. */
    public function needsInstaller(): bool
    {
        return $this === self::Forge || $this === self::NeoForge;
    }

    /** The startup variable an installer-based egg reads its version from. */
    public function installerVariable(): ?string
    {
        return match ($this) {
            self::Forge => 'FORGE_VERSION',
            self::NeoForge => 'NEOFORGE_VERSION',
            default => null,
        };
    }
}
