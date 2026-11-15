<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Account;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

/**
 * The versions of the Terms of Service and Privacy Policy the user was shown, so a document
 * published while the dialog was open is not accepted unseen.
 */
class AcceptLegalDocumentsRequest extends ClientApiRequest
{
    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'terms_version' => ['required', 'integer', 'min:1'],
            'privacy_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
