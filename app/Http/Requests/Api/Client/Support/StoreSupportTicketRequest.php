<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Support;

/**
 * A new ticket: subject, category, an optional server of the player's, and the first message.
 */
class StoreSupportTicketRequest extends ReplySupportTicketRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'category_id' => ['required', 'integer', 'exists:support_categories,id'],
            'server' => ['sometimes', 'nullable', 'string', 'max:36'],
            ...parent::rules(),
        ];
    }

    public function ticketSubject(): string
    {
        return mb_trim($this->string('subject')->toString());
    }

    public function categoryId(): int
    {
        return $this->integer('category_id');
    }

    public function serverIdentifier(): ?string
    {
        return $this->filled('server') ? $this->string('server')->toString() : null;
    }
}
