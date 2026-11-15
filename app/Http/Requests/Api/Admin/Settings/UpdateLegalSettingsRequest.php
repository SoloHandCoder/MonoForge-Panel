<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Settings;

use Closure;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Services\Legal\LegalSettings;

class UpdateLegalSettingsRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminSettingsUpdate];
    }

    /**
     * Blank content uses the starter text and a blank URL hosts the document on the panel.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'content' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && mb_strlen($value, '8bit') > LegalSettings::MAX_CONTENT_BYTES) {
                        $fail('The document may be at most '.number_format(LegalSettings::MAX_CONTENT_BYTES).' bytes long.');
                    }
                },
            ],
            'external_url' => [
                'nullable',
                'string',
                'max:191',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && mb_trim($value) !== '' && ! LegalSettings::isSafeUrl(mb_trim($value))) {
                        $fail('The external URL must be a full https:// address.');
                    }
                },
            ],
            'reaccept' => ['required', 'boolean'],
        ];
    }

    /** Rename fields to be more clear in error messages. */
    public function attributes(): array
    {
        return [
            'content' => 'Content',
            'external_url' => 'External URL',
            'reaccept' => 'Ask players to accept again',
        ];
    }
}
