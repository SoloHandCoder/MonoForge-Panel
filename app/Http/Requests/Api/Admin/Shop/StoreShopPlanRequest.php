<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class StoreShopPlanRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminShopUpdate];
    }

    /**
     * Resource fields use the units of a server's build: MB for memory, swap and disk,
     * percent of one thread for cpu. An empty node list allows every deployable node.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:shop_categories,id'],
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visible' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'price_tokens' => ['required', 'integer', 'min:0', 'max:100000000'],
            'memory' => ['required', 'integer', 'min:128', 'max:4194304'],
            'swap' => ['sometimes', 'integer', 'min:0', 'max:4194304'],
            'disk' => ['required', 'integer', 'min:128', 'max:104857600'],
            'cpu' => ['required', 'integer', 'min:1', 'max:100000'],
            'io' => ['sometimes', 'integer', 'min:10', 'max:1000'],
            'databases' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'backups' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'allocations' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'egg_ids' => ['required', 'array', 'min:1', 'max:500'],
            'egg_ids.*' => ['integer', 'distinct', 'exists:eggs,id'],
            'node_ids' => ['nullable', 'array', 'max:500'],
            'node_ids.*' => ['integer', 'distinct', 'exists:nodes,id'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'category_id' => 'Category',
            'price_tokens' => 'Price',
            'egg_ids' => 'Eggs',
            'node_ids' => 'Nodes',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function planAttributes(): array
    {
        $nodeIds = array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, (array) $this->input('node_ids', [])));

        return [
            'category_id' => $this->integer('category_id'),
            'name' => $this->string('name')->trim()->toString(),
            'description' => $this->filled('description') ? $this->string('description')->trim()->toString() : null,
            'visible' => $this->boolean('visible', true),
            'sort' => $this->integer('sort'),
            'price_tokens' => $this->integer('price_tokens'),
            'memory' => $this->integer('memory'),
            'swap' => $this->integer('swap'),
            'disk' => $this->integer('disk'),
            'cpu' => $this->integer('cpu'),
            'io' => $this->integer('io', 500),
            'databases' => $this->integer('databases'),
            'backups' => $this->integer('backups'),
            'allocations' => $this->integer('allocations'),
            'egg_ids' => array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, (array) $this->input('egg_ids'))),
            'node_ids' => $nodeIds === [] ? null : $nodeIds,
        ];
    }
}
