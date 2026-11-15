<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Support;

use Illuminate\Validation\Rule;
use Pterodactyl\Enum\SupportTicketStatus;

/**
 * Staff change a ticket's status, assignee or category; each field is optional.
 */
class UpdateSupportTicketRequest extends SupportReplyRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(SupportTicketStatus::class)],
            // Only administrators work the queue, so only they can be assigned.
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('root_admin', true)],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:support_categories,id'],
        ];
    }
}
