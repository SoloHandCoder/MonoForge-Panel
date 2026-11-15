<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Client;

use Illuminate\Http\Request;
use Pterodactyl\Models\PermissionTemplate;
use Pterodactyl\Services\Subusers\PermissionTemplateCatalog;

/**
 * The subuser permission templates the user can pick from.
 *
 * @phpstan-import-type TemplateRow from PermissionTemplateCatalog
 *
 * @extends ClientResource<array{templates: list<TemplateRow>}>
 */
class PermissionTemplateListResource extends ClientResource
{
    public const string RESOURCE_NAME = 'permission_template_list';

    public function resourceName(): string
    {
        return self::RESOURCE_NAME;
    }

    public function toArray(Request $request): array
    {
        return [
            'templates' => $this->resource['templates'],
            'personal_limit' => PermissionTemplate::MAX_PER_ACCOUNT,
        ];
    }
}
