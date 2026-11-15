<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Support;

use Pterodactyl\Http\Requests\Api\Client\Support\ReplySupportTicketRequest;

class StoreCannedReplyRequest extends SupportReplyRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:'.ReplySupportTicketRequest::MAX_BODY],
        ];
    }

    /**
     * @return array{title: string, body: string}
     */
    public function replyAttributes(): array
    {
        return [
            'title' => mb_trim($this->string('title')->toString()),
            'body' => mb_trim($this->string('body')->toString()),
        ];
    }
}
