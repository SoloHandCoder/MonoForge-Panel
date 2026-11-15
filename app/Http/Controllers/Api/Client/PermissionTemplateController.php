<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseFromResource;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Requests\Api\Client\Account\PermissionTemplateRequest;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Http\Resources\Api\Client\PermissionTemplateListResource;
use Pterodactyl\Http\Resources\Api\Client\PermissionTemplateResource;
use Pterodactyl\Models\PermissionTemplate;
use Pterodactyl\Services\Subusers\PermissionTemplateCatalog;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Account Permission Templates', "Subuser permission presets: built-in, global and the authenticated user's own.")]
class PermissionTemplateController extends ClientApiController
{
    public const array LIST_EXAMPLE = [
        'object' => PermissionTemplateListResource::RESOURCE_NAME,
        'attributes' => [
            'templates' => [
                [
                    'key' => 'builtin:viewer',
                    'id' => null,
                    'source' => 'builtin',
                    'name' => 'Viewer',
                    'permissions' => ['websocket.connect', 'file.read', 'file.read-content'],
                ],
                [
                    'key' => 'personal:1',
                    'id' => 1,
                    'source' => 'personal',
                    'name' => 'Event staff',
                    'permissions' => ['websocket.connect', 'control.console'],
                ],
            ],
            'personal_limit' => PermissionTemplate::MAX_PER_ACCOUNT,
        ],
    ];

    private const array LIMIT_EXAMPLE = [
        'errors' => [[
            'code' => 'DisplayException',
            'status' => '400',
            'detail' => 'You can keep up to 25 permission templates.',
        ]],
    ];

    public function __construct(private readonly PermissionTemplateCatalog $templates) {}

    /**
     * The templates the user can pick when inviting a subuser.
     */
    #[Endpoint('List permission templates', "Returns the built-in templates (Viewer, Moderator, Builder, Co-owner), the global templates an admin made and the authenticated user's own. A template only pre-fills the subuser form; the user can still only grant the permissions they hold on that server.")]
    #[ScribeResponse(self::LIST_EXAMPLE, description: 'Templates returned.')]
    public function index(ClientApiRequest $request): PermissionTemplateListResource
    {
        return PermissionTemplateListResource::make(['templates' => $this->templates->forUser($request->user())]);
    }

    /**
     * Save a template on the user's account.
     */
    #[Endpoint('Create permission template', "Saves a permission template on the authenticated user's account. Unknown permissions are refused; console access is always included.")]
    #[ResponseFromResource(PermissionTemplateResource::class, PermissionTemplate::class, status: 201, description: 'Template created.', resourceKey: 'permission_template')]
    #[ScribeResponse(self::LIMIT_EXAMPLE, status: 400, description: 'The account already has the most templates it may keep.')]
    public function store(PermissionTemplateRequest $request): JsonResponse
    {
        $user = $request->user();
        throw_if(
            $user->permissionTemplates()->count() >= PermissionTemplate::MAX_PER_ACCOUNT,
            DisplayException::class,
            'You can keep up to '.PermissionTemplate::MAX_PER_ACCOUNT.' permission templates.',
        );

        $template = $user->permissionTemplates()->create($this->attributes($request));

        Activity::event('user:permission-template.create')->subject($user)->property('name', $template->name)->log();

        return PermissionTemplateResource::make($template)->respond(JsonResponse::HTTP_CREATED);
    }

    /**
     * Change one of the user's templates. Subusers set up from it keep their permissions.
     */
    #[Endpoint('Update permission template', "Renames one of the authenticated user's templates or changes its permissions. Subusers invited with it keep the permissions they have.")]
    #[UrlParam('template', 'integer', 'The ID of the permission template.', example: 1)]
    #[ResponseFromResource(PermissionTemplateResource::class, PermissionTemplate::class, description: 'Template updated.', resourceKey: 'permission_template')]
    public function update(PermissionTemplateRequest $request, int $template): PermissionTemplateResource
    {
        $user = $request->user();
        $model = $user->permissionTemplates()->findOrFail($template);
        $model->fill($this->attributes($request))->save();

        Activity::event('user:permission-template.update')->subject($user)->property('name', $model->name)->log();

        return PermissionTemplateResource::make($model);
    }

    /**
     * Delete one of the user's templates. Subusers set up from it keep their permissions.
     */
    #[Endpoint('Delete permission template', "Deletes one of the authenticated user's templates. Subusers invited with it keep the permissions they have.")]
    #[UrlParam('template', 'integer', 'The ID of the permission template.', example: 1)]
    #[ScribeResponse(status: 204, description: 'Template deleted.')]
    public function delete(ClientApiRequest $request, int $template): JsonResponse
    {
        $user = $request->user();
        $model = $user->permissionTemplates()->findOrFail($template);
        $model->delete();

        Activity::event('user:permission-template.delete')->subject($user)->property('name', $model->name)->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * @return array{name: string, permissions: list<string>}
     */
    private function attributes(PermissionTemplateRequest $request): array
    {
        return [
            'name' => mb_trim($request->string('name')->toString()),
            'permissions' => $this->templates->clean($request->array('permissions')),
        ];
    }
}
