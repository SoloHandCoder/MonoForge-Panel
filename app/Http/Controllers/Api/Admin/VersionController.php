<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Http\Requests\Api\Admin\GetVersionRequest;
use Pterodactyl\Services\Helpers\SoftwareVersionService;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('System', 'Endpoints for system metadata and global admin utilities.')]
class VersionController extends AdminApiController
{
    private const array VERSION_EXAMPLE = [
        'current' => '3f9c2ab',
        'latest' => '3f9c2ab',
        'is_latest' => true,
        'daemon' => '',
        'discord' => '',
        'donations' => '',
    ];

    /**
     * Return the running and latest MonoForge builds, and daemon version information.
     */
    #[Endpoint('Get version information', 'Returns the running and latest MonoForge builds (short commits), plus Wings and project metadata.')]
    #[ScribeResponse(self::VERSION_EXAMPLE, description: 'Version information returned.')]
    public function __invoke(GetVersionRequest $request, SoftwareVersionService $version): JsonResponse
    {
        return new JsonResponse([
            'current' => $version->getCurrentPanel(),
            'latest' => $version->getPanel(),
            'is_latest' => $version->isLatestPanel(),
            'daemon' => $version->getDaemon(),
            'discord' => $version->getDiscord(),
            'donations' => $version->getDonations(),
        ]);
    }
}
