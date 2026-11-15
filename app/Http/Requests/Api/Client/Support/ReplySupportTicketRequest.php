<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Support;

use Illuminate\Http\UploadedFile;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Rules\SupportImage;
use Pterodactyl\Services\Support\SupportAttachmentStore;

/**
 * A message on a ticket: markdown text and up to three images.
 */
class ReplySupportTicketRequest extends ClientApiRequest
{
    public const int MAX_BODY = 10000;

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:2', 'max:'.self::MAX_BODY],
            'attachments' => ['sometimes', 'array', 'max:'.SupportAttachmentStore::MAX_PER_MESSAGE],
            'attachments.*' => ['file', 'max:'.(SupportAttachmentStore::MAX_BYTES / 1024), new SupportImage],
        ];
    }

    public function messageBody(): string
    {
        return mb_trim($this->string('body')->toString());
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
}
