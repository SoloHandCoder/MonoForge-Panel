<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Plugins\Sources;

use Pterodactyl\Data\Plugins\MarketplaceSearch;
use Pterodactyl\Data\Plugins\MarketplaceSearchResult;
use Pterodactyl\Data\Plugins\MarketplaceVersion;
use Pterodactyl\Enum\MarketplaceProjectType;
use Pterodactyl\Enum\MarketplaceSource;
use Pterodactyl\Exceptions\Plugins\MarketplaceException;
use Pterodactyl\Services\Plugins\ServerPlatform;

/** A catalog of plugins, mods or modpacks the panel can search and install from. */
interface MarketplaceCatalog
{
    public function source(): MarketplaceSource;

    /** Whether the catalog can be used at all (CurseForge needs an API key). */
    public function available(): bool;

    /** Whether the catalog has projects of this type for this platform. */
    public function supports(MarketplaceProjectType $type, ServerPlatform $platform): bool;

    /** @throws MarketplaceException */
    public function search(MarketplaceSearch $search): MarketplaceSearchResult;

    /**
     * The project's versions that suit the platform, newest first. Modpack versions are not
     * filtered, so the user can see which loader and game version each one needs.
     *
     * @return list<MarketplaceVersion>
     *
     * @throws MarketplaceException
     */
    public function versions(string $project, MarketplaceProjectType $type, ServerPlatform $platform): array;

    /**
     * One version of the project, fetched from the catalog (never from the user), or null
     * when it does not exist or belongs to another project. A catalog with a file per platform
     * (Hangar) picks the file for the given platform.
     *
     * @throws MarketplaceException
     */
    public function version(string $project, string $version, MarketplaceProjectType $type, ?ServerPlatform $platform = null): ?MarketplaceVersion;
}
