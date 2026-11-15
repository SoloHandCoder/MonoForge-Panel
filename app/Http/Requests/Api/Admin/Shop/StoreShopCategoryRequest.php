<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Illuminate\Validation\Rule;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Models\Billing\ShopCategory;

class StoreShopCategoryRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminShopUpdate];
    }

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name' => ['required', 'string', 'max:191'],
            'slug' => [
                'required',
                'string',
                'max:191',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('shop_categories', 'slug')->ignore($category instanceof ShopCategory ? $category->id : null),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'visible' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{name: string, slug: string, description: string|null, visible: bool}
     */
    public function categoryAttributes(): array
    {
        return [
            'name' => $this->string('name')->trim()->toString(),
            'slug' => $this->string('slug')->trim()->toString(),
            'description' => $this->filled('description') ? $this->string('description')->trim()->toString() : null,
            'visible' => $this->boolean('visible', true),
        ];
    }
}
