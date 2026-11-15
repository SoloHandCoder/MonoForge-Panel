<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Shop;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Shop\DeleteShopItemRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\GetShopRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\ReorderShopCategoriesRequest;
use Pterodactyl\Http\Requests\Api\Admin\Shop\StoreShopCategoryRequest;
use Pterodactyl\Models\Billing\ShopCategory;
use Pterodactyl\Services\Billing\ShopPresenter;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Shop', 'The token shop catalog: categories, plans, billing periods, price multipliers, add-ons and limits.')]
class ShopCategoryController extends AdminApiController
{
    private const array EXAMPLE = [
        'id' => 1,
        'name' => 'Minecraft',
        'slug' => 'minecraft',
        'description' => 'Java and Bedrock servers.',
        'sort' => 0,
        'visible' => true,
        'plans_count' => 3,
    ];

    #[Endpoint('List shop categories', 'Returns every shop category in the order the store shows them, with how many plans each holds.')]
    #[ScribeResponse(['data' => [self::EXAMPLE]], description: 'Categories returned.')]
    public function index(GetShopRequest $request): JsonResponse
    {
        $categories = ShopCategory::query()->ordered()->withCount('plans')->get();

        return new JsonResponse([
            'data' => $categories->map(fn (ShopCategory $category): array => $this->present($category))->all(),
        ]);
    }

    #[Endpoint('Create shop category', 'Creates a category at the end of the list.')]
    #[ScribeResponse(self::EXAMPLE, status: 201, description: 'Category created.')]
    public function store(StoreShopCategoryRequest $request): JsonResponse
    {
        $category = ShopCategory::query()->create([
            ...$request->categoryAttributes(),
            'sort' => ShopCategory::query()->count(),
        ]);

        Activity::event('admin:shop.category.create')->property('name', $category->name)->log();

        return new JsonResponse($this->present($category->loadCount('plans')), Response::HTTP_CREATED);
    }

    #[Endpoint('Update shop category', 'Updates a category.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Category updated.')]
    public function update(StoreShopCategoryRequest $request, ShopCategory $category): JsonResponse
    {
        $category->update($request->categoryAttributes());

        Activity::event('admin:shop.category.update')->property('name', $category->name)->log();

        return new JsonResponse($this->present($category->loadCount('plans')));
    }

    #[Endpoint('Reorder shop categories', 'Sets the order the store shows the categories in, first to last.')]
    #[ScribeResponse(status: 204, description: 'Order saved.')]
    public function reorder(ReorderShopCategoriesRequest $request): Response
    {
        DB::transaction(function () use ($request): void {
            foreach ($request->ids() as $position => $id) {
                ShopCategory::query()->whereKey($id)->update(['sort' => $position]);
            }
        });

        Activity::event('admin:shop.category.reorder')->log();

        return $this->returnNoContent();
    }

    /**
     * @throws DisplayException when the category still has plans
     */
    #[Endpoint('Delete shop category', 'Deletes an empty category. Move or delete its plans first.')]
    #[ScribeResponse(status: 204, description: 'Category deleted.')]
    public function destroy(DeleteShopItemRequest $request, ShopCategory $category): Response
    {
        throw_if($category->plans()->exists(), DisplayException::class, 'This category still has plans. Move or delete them first.');

        $category->delete();

        Activity::event('admin:shop.category.delete')->property('name', $category->name)->log();

        return $this->returnNoContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ShopCategory $category): array
    {
        return [...ShopPresenter::category($category), 'plans_count' => $category->plans_count ?? 0];
    }
}
