<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Shop;

use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class StoreTokenPackRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminSettingsUpdate];
    }

    /**
     * The Tebex package ID must be the numeric ID: Tebex webhooks name the paid package by it.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'tebex_package_id' => ['required', 'string', 'max:20', 'regex:/^[0-9]+$/'],
            'name' => ['required', 'string', 'max:191'],
            'tokens' => ['required', 'integer', 'min:1', 'max:100000000'],
            'bonus_tokens' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'visible' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:-100000', 'max:100000'],
        ];
    }

    /**
     * @return array{tebex_package_id: string, name: string, tokens: int, bonus_tokens: int, visible: bool, sort: int}
     */
    public function packAttributes(): array
    {
        return [
            'tebex_package_id' => $this->string('tebex_package_id')->toString(),
            'name' => mb_trim($this->string('name')->toString()),
            'tokens' => $this->integer('tokens'),
            'bonus_tokens' => $this->integer('bonus_tokens'),
            'visible' => $this->boolean('visible', true),
            'sort' => $this->integer('sort'),
        ];
    }
}
