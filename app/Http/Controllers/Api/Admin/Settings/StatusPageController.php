<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Settings;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Settings\GetSettingsRequest;
use Pterodactyl\Http\Requests\Api\Admin\Settings\UpdateStatusPageSettingsRequest;
use Pterodactyl\Services\Status\StatusPageSettings;

/**
 * The public status page: whether it is on, and which nodes it shows under which display name.
 */
#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Settings', 'View and update global panel settings.')]
class StatusPageController extends AdminApiController
{
    private const array SETTINGS_EXAMPLE = [
        'enabled' => true,
        'public_url' => '/status',
        'nodes' => [
            [
                'id' => 1,
                'name' => 'fsn-wings-01',
                'location' => 'EU',
                'visible' => true,
                'display_name' => 'EU Node 1',
                'custom_name' => '',
                'default_name' => 'EU Node 1',
            ],
        ],
    ];

    #[Endpoint('Get status page settings', 'Returns whether the public status page is on and, for every node, whether it is shown and its public display name.')]
    #[ScribeResponse(self::SETTINGS_EXAMPLE, description: 'Status page settings returned.')]
    public function index(GetSettingsRequest $request): JsonResponse
    {
        return new JsonResponse($this->settings());
    }

    #[Endpoint('Update status page settings', 'Turns the public status page on or off and sets, per node, whether it is shown and its display name. A blank display name uses the default: the location short name and "Node N". Nodes left out keep their choice.')]
    #[BodyParam('enabled', 'boolean', 'Whether the public status page is on.', required: true, example: true)]
    #[BodyParam('nodes', 'object[]', 'The nodes to change.', required: true, example: [['id' => 1, 'visible' => true, 'display_name' => 'EU Node 1']])]
    #[BodyParam('nodes[].id', 'integer', 'The node ID.', required: true, example: 1)]
    #[BodyParam('nodes[].visible', 'boolean', 'Whether the status page shows the node.', required: true, example: true)]
    #[BodyParam('nodes[].display_name', 'string', 'The public name of the node. Blank uses the default.', required: false, example: 'EU Node 1', nullable: true)]
    #[ScribeResponse(self::SETTINGS_EXAMPLE, description: 'Status page settings updated.')]
    public function update(UpdateStatusPageSettingsRequest $request): JsonResponse
    {
        $nodes = [];
        foreach ($request->array('nodes') as $node) {
            if (! is_array($node) || ! is_numeric($node['id'] ?? null)) {
                continue;
            }

            $name = $node['display_name'] ?? '';
            $nodes[(int) $node['id']] = [
                'visible' => filter_var($node['visible'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'name' => is_string($name) ? $name : '',
            ];
        }

        StatusPageSettings::save($request->boolean('enabled'), $nodes);

        return new JsonResponse($this->settings());
    }

    /**
     * @return array{enabled: bool, public_url: string, nodes: list<array{id: int, name: string, location: string, visible: bool, display_name: string, custom_name: string, default_name: string}>}
     */
    private function settings(): array
    {
        return [
            'enabled' => StatusPageSettings::enabled(),
            'public_url' => '/status',
            'nodes' => array_map(fn (array $row): array => [
                'id' => $row['node']->id,
                'name' => $row['node']->name,
                'location' => (string) $row['node']->location->short,
                'visible' => $row['visible'],
                'display_name' => $row['name'],
                'custom_name' => $row['custom_name'],
                'default_name' => $row['default_name'],
            ], StatusPageSettings::nodes()),
        ];
    }
}
