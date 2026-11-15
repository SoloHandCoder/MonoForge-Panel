<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Support;

use Illuminate\Validation\Rule;
use Pterodactyl\Enum\SupportTicketStatus;

/**
 * The staff ticket list and its filters.
 */
class GetSupportTicketsRequest extends SupportReadRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in([...SupportTicketStatus::values(), 'active'])],
            'category' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'assigned' => ['sometimes', 'nullable', 'string', 'regex:/^(me|none|[1-9][0-9]{0,9})$/'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }
}
