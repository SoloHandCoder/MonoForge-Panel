<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Admin;

use Illuminate\Http\Request;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Models\PermissionTemplate;
use Pterodactyl\Services\Subusers\PermissionTemplateCatalog;

/**
 * A global subuser permission template.
 *
 * @extends AdminResource<PermissionTemplate>
 */
#[ResponseField('permissions', schema: ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['websocket.connect', 'control.console', 'file.read']])]
class PermissionTemplateResource extends AdminResource
{
    public function resourceName(): string
    {
        return PermissionTemplate::RESOURCE_NAME;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'permissions' => resolve(PermissionTemplateCatalog::class)->clean($this->resource->permissions),
            'created_at' => $this->formatTimestamp($this->resource->created_at),
            'updated_at' => $this->formatTimestamp($this->resource->updated_at),
        ];
    }
}
