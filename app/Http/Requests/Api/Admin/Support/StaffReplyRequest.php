<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Support;

use Illuminate\Http\UploadedFile;
use Pterodactyl\Http\Requests\Api\Client\Support\ReplySupportTicketRequest;
use Pterodactyl\Rules\SupportImage;
use Pterodactyl\Services\Support\SupportAttachmentStore;

/**
 * A staff reply, or an internal note only staff see.
 */
class StaffReplyRequest extends SupportReplyRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:2', 'max:'.ReplySupportTicketRequest::MAX_BODY],
            'internal' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'array', 'max:'.SupportAttachmentStore::MAX_PER_MESSAGE],
            'attachments.*' => ['file', 'max:'.(SupportAttachmentStore::MAX_BYTES / 1024), new SupportImage],
        ];
    }

    public function messageBody(): string
    {
        return mb_trim($this->string('body')->toString());
    }

    public function internal(): bool
    {
        return $this->boolean('internal');
    }

    /**
     * @return list<UploadedFile>
     */
    public function images(): array
    {
        $files = $this->file('attachments');
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        return is_array($files) ? array_values($files) : [];
    }

    /**
     * A multipart form sends "true" and "false" as text.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('internal')) {
            $value = filter_var($this->input('internal'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($value !== null) {
                $this->merge(['internal' => $value]);
            }
        }
    }
}
