<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class ReorderShopCategoriesRequest extends AdminApiRequest
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
        return [
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer', 'distinct', 'exists:shop_categories,id'],
        ];
    }

    /**
     * The category ids in their new order.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, (array) $this->input('ids')));
    }
}
