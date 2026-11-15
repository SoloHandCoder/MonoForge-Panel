<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Client;

use Illuminate\Http\Request;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Models\PermissionTemplate;
use Pterodactyl\Services\Subusers\PermissionTemplateCatalog;

/**
 * One subuser permission template, in the shape the template list uses.
 *
 * @extends ClientResource<PermissionTemplate>
 */
#[ResponseField('key', example: 'personal:1')]
#[ResponseField('source', example: 'personal', enum: ['builtin', 'global', 'personal'])]
#[ResponseField('permissions', schema: ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['websocket.connect', 'control.console', 'file.read']])]
class PermissionTemplateResource extends ClientResource
{
    public function resourceName(): string
    {
        return PermissionTemplate::RESOURCE_NAME;
    }

    public function toArray(Request $request): array
    {
        return resolve(PermissionTemplateCatalog::class)->row($this->resource);
    }
}
