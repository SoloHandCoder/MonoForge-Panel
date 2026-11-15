<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Support\StoreSupportCategoryRequest;
use Pterodactyl\Http\Requests\Api\Admin\Support\SupportReadRequest;
use Pterodactyl\Http\Requests\Api\Admin\Support\SupportReplyRequest;
use Pterodactyl\Models\Support\SupportCategory;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Support', 'The support ticket queue: tickets, replies, internal notes, assignment, canned replies and categories.')]
class SupportCategoryController extends AdminApiController
{
    public const array CATEGORY_EXAMPLE = ['id' => 1, 'name' => 'Billing', 'sort' => 1, 'tickets' => 4];

    public const array CATEGORY_SCHEMA = [
        'type' => 'object',
        'required' => ['id', 'name', 'sort', 'tickets'],
        'properties' => [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'sort' => ['type' => 'integer', 'description' => 'Lower sorts first.'],
            'tickets' => ['type' => 'integer', 'description' => 'How many tickets have this category.'],
        ],
    ];

    #[Endpoint('List support categories', 'Returns the ticket categories in the order players see them, with how many tickets each has.')]
    #[ScribeResponse(['data' => [self::CATEGORY_EXAMPLE]], description: 'Categories returned.')]
    #[ResponseField('data', 'object[]', 'The categories.', schema: ['type' => 'array', 'items' => self::CATEGORY_SCHEMA])]
    public function index(SupportReadRequest $request): JsonResponse
    {
        $categories = SupportCategory::ordered()->withCount('tickets')->get();

        return new JsonResponse(['data' => $categories->map(fn (SupportCategory $category): array => $this->present($category))->values()->all()]);
    }

    #[Endpoint('Create a support category', 'Adds a category players can choose for a ticket.')]
    #[ScribeResponse(['data' => self::CATEGORY_EXAMPLE], status: 201, description: 'Category created.')]
    #[ResponseField('data', 'object', 'The category.', schema: self::CATEGORY_SCHEMA)]
    public function store(StoreSupportCategoryRequest $request): JsonResponse
    {
        $category = SupportCategory::query()->create($request->categoryAttributes());

        Activity::event('admin:support.category.create')->property(['name' => $category->name])->log();

        return new JsonResponse(['data' => $this->present($category->loadCount('tickets'))], Response::HTTP_CREATED);
    }

    #[Endpoint('Update a support category', 'Renames or reorders a category. Its tickets keep it.')]
    #[UrlParam('support_category', 'integer', 'The support category ID.', example: 1)]
    #[ScribeResponse(['data' => self::CATEGORY_EXAMPLE], description: 'Category updated.')]
    #[ResponseField('data', 'object', 'The category.', schema: self::CATEGORY_SCHEMA)]
    public function update(StoreSupportCategoryRequest $request, int $supportCategory): JsonResponse
    {
        $category = SupportCategory::query()->findOrFail($supportCategory);
        $category->fill($request->categoryAttributes())->save();

        Activity::event('admin:support.category.update')->property(['name' => $category->name])->log();

        return new JsonResponse(['data' => $this->present($category->loadCount('tickets'))]);
    }

    #[Endpoint('Delete a support category', 'Deletes a category. Its tickets stay, without a category.')]
    #[UrlParam('support_category', 'integer', 'The support category ID.', example: 1)]
    #[ScribeResponse(status: 204, description: 'Category deleted.')]
    public function destroy(SupportReplyRequest $request, int $supportCategory): Response
    {
        $category = SupportCategory::query()->findOrFail($supportCategory);
        $category->delete();

        Activity::event('admin:support.category.delete')->property(['name' => $category->name])->log();

        return $this->returnNoContent();
    }

    /**
     * @return array{id: int, name: string, sort: int, tickets: int}
     */
    private function present(SupportCategory $category): array
    {
        $count = $category->getAttribute('tickets_count');

        return ['id' => $category->id, 'name' => $category->name, 'sort' => $category->sort, 'tickets' => is_numeric($count) ? (int) $count : 0];
    }
}
