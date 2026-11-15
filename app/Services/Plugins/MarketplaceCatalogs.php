<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Plugins;

use Pterodactyl\Enum\MarketplaceProjectType;
use Pterodactyl\Enum\MarketplaceSource;
use Pterodactyl\Exceptions\Plugins\MarketplaceException;
use Pterodactyl\Services\Plugins\Sources\CurseForgeCatalog;
use Pterodactyl\Services\Plugins\Sources\HangarCatalog;
use Pterodactyl\Services\Plugins\Sources\MarketplaceCatalog;
use Pterodactyl\Services\Plugins\Sources\ModrinthCatalog;

/** The catalogs, and which of them can be used for a server. */
final readonly class MarketplaceCatalogs
{
    public function __construct(
        private ModrinthCatalog $modrinth,
        private HangarCatalog $hangar,
        private CurseForgeCatalog $curseForge,
    ) {}

    public function modrinth(): ModrinthCatalog
    {
        return $this->modrinth;
    }

    /** @throws MarketplaceException when the catalog cannot be used for this type or platform */
    public function for(MarketplaceSource $source, MarketplaceProjectType $type, ServerPlatform $platform): MarketplaceCatalog
    {
        $catalog = match ($source) {
            MarketplaceSource::Modrinth => $this->modrinth,
            MarketplaceSource::Hangar => $this->hangar,
            MarketplaceSource::CurseForge => $this->curseForge,
        };

        if (! $catalog->available()) {
            throw new MarketplaceException("{$source->label()} is not set up on this panel.");
        }

        if (! $catalog->supports($type, $platform)) {
            throw new MarketplaceException("{$source->label()} has no {$type->value}s for this server.");
        }

        return $catalog;
    }

    /**
     * The catalogs that can be searched for each type on this platform.
     *
     * @return array<string, list<string>> project type => sources
     */
    public function available(ServerPlatform $platform): array
    {
        $available = [];
        foreach (MarketplaceProjectType::cases() as $type) {
            $available[$type->value] = [];
            foreach ([$this->modrinth, $this->hangar, $this->curseForge] as $catalog) {
                if ($catalog->available() && $catalog->supports($type, $platform)) {
                    $available[$type->value][] = $catalog->source()->value;
                }
            }
        }

        return $available;
    }
}
