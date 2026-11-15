<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseFromResource;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Settings\GetSettingsRequest;
use Pterodactyl\Http\Requests\Api\Admin\Settings\PermissionTemplates\DeletePermissionTemplateRequest;
use Pterodactyl\Http\Requests\Api\Admin\Settings\PermissionTemplates\StorePermissionTemplateRequest;
use Pterodactyl\Http\Resources\Api\Admin\PermissionTemplateResource;
use Pterodactyl\Http\Resources\Api\ApiResourceCollection;
use Pterodactyl\Models\PermissionTemplate;
use Pterodactyl\Services\Subusers\PermissionTemplateCatalog;

/**
 * Global subuser permission templates, offered to every user next to the built-in ones.
 */
#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Settings', 'View and update global panel settings.')]
class PermissionTemplateController extends AdminApiController
{
    private const array LIMIT_EXAMPLE = [
        'errors' => [[
            'code' => 'DisplayException',
            'status' => '400',
            'detail' => 'The panel can keep up to 50 global permission templates.',
        ]],
    ];

    public function __construct(private readonly PermissionTemplateCatalog $templates) {}

    #[Endpoint('List global permission templates', 'Returns the global subuser permission templates, by name. The built-in templates are not listed here.')]
    #[ResponseFromResource(PermissionTemplateResource::class, PermissionTemplate::class, description: 'Global templates returned.', collection: true, resourceKey: 'permission_template')]
    public function index(GetSettingsRequest $request): ApiResourceCollection
    {
        return PermissionTemplateResource::collection(PermissionTemplate::query()->global()->orderBy('name')->get());
    }

    #[Endpoint('Create global permission template', 'Creates a subuser permission template every user can pick. Console access is always included.')]
    #[ResponseFromResource(PermissionTemplateResource::class, PermissionTemplate::class, status: 201, description: 'Global template created.', resourceKey: 'permission_template')]
    #[ScribeResponse(self::LIMIT_EXAMPLE, status: 400, description: 'The panel already has the most global templates it may keep.')]
    public function store(StorePermissionTemplateRequest $request): JsonResponse
    {
        throw_if(
            PermissionTemplate::query()->global()->count() >= PermissionTemplate::MAX_GLOBAL,
            DisplayException::class,
            'The panel can keep up to '.PermissionTemplate::MAX_GLOBAL.' global permission templates.',
        );

        $template = PermissionTemplate::query()->create($this->attributes($request));

        Activity::event('admin:permission-template.create')->property('name', $template->name)->log();

        return PermissionTemplateResource::make($template)->respond(Response::HTTP_CREATED);
    }

    #[Endpoint('Update global permission template', 'Renames a global template or changes its permissions. Subusers invited with it keep the permissions they have.')]
    #[ResponseFromResource(PermissionTemplateResource::class, PermissionTemplate::class, description: 'Global template updated.', resourceKey: 'permission_template')]
    public function update(StorePermissionTemplateRequest $request, PermissionTemplate $permissionTemplate): PermissionTemplateResource
    {
        $template = $this->global($permissionTemplate);
        $template->fill($this->attributes($request))->save();

        Activity::event('admin:permission-template.update')->property('name', $template->name)->log();

        return PermissionTemplateResource::make($template);
    }

    #[Endpoint('Delete global permission template', 'Deletes a global template. Subusers invited with it keep the permissions they have.')]
    #[ScribeResponse(status: 204, description: 'Global template deleted.')]
    public function destroy(DeletePermissionTemplateRequest $request, PermissionTemplate $permissionTemplate): Response
    {
        $template = $this->global($permissionTemplate);
        $template->delete();

        Activity::event('admin:permission-template.delete')->property('name', $template->name)->log();

        return $this->returnNoContent();
    }

    /**
     * Only global templates are managed here; a user's own templates are theirs.
     */
    private function global(PermissionTemplate $template): PermissionTemplate
    {
        abort_if($template->user_id !== null, Response::HTTP_NOT_FOUND);

        return $template;
    }

    /**
     * @return array{name: string, permissions: list<string>}
     */
    private function attributes(StorePermissionTemplateRequest $request): array
    {
        return [
            'name' => mb_trim($request->string('name')->toString()),
            'permissions' => $this->templates->clean($request->array('permissions')),
        ];
    }
}
