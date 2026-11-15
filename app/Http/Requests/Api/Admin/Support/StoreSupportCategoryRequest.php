<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Support;

class StoreSupportCategoryRequest extends SupportReplyRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * @return array{name: string, sort: int}
     */
    public function categoryAttributes(): array
    {
        return [
            'name' => mb_trim($this->string('name')->toString()),
            'sort' => $this->integer('sort'),
        ];
    }
}
