<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Tunnels;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Tunnels\GetTunnelSettingsRequest;
use Pterodactyl\Http\Requests\Api\Admin\Tunnels\UpdateTunnelSettingsRequest;
use Pterodactyl\Services\Tunnels\TunnelSettings;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Tunnels', 'The playit.gg tunnels that give servers their public address.')]
class TunnelSettingsController extends AdminApiController
{
    private const array SETTINGS_EXAMPLE = [
        'subdomains_enabled' => false,
        'base_domain' => 'monoforge.net',
        'blocklist' => ['www', 'panel', 'admin'],
    ];

    /**
     * Return the custom subdomain settings.
     */
    #[Endpoint('Get tunnel settings', 'Returns whether players may pick a custom subdomain at checkout, the base domain and the reserved names.')]
    #[ScribeResponse(self::SETTINGS_EXAMPLE, description: 'Tunnel settings returned.')]
    public function show(GetTunnelSettingsRequest $request): JsonResponse
    {
        return new JsonResponse($this->settings());
    }

    /**
     * Update the custom subdomain settings.
     */
    #[Endpoint('Update tunnel settings', 'Turns custom subdomains on or off and sets the base domain and the reserved names. Creating the DNS records is not available yet; chosen names are stored on the tunnel.')]
    #[ScribeResponse(self::SETTINGS_EXAMPLE, description: 'Tunnel settings updated.')]
    public function update(UpdateTunnelSettingsRequest $request): JsonResponse
    {
        $blocklist = array_values(array_filter($request->array('blocklist'), is_string(...)));

        TunnelSettings::save($request->boolean('subdomains_enabled'), $request->string('base_domain')->toString(), $blocklist);

        return new JsonResponse($this->settings());
    }

    /**
     * @return array{subdomains_enabled: bool, base_domain: string, blocklist: list<string>}
     */
    private function settings(): array
    {
        return [
            // The stored switch, so the form shows what was saved even without a base domain.
            'subdomains_enabled' => TunnelSettings::switchedOn(),
            'base_domain' => TunnelSettings::baseDomain(),
            'blocklist' => TunnelSettings::blocklist(),
        ];
    }
}
