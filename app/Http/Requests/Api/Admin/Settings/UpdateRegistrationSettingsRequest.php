<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Settings;

use Illuminate\Validation\Rule;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;
use Pterodactyl\Services\Captcha\CaptchaSettings;

class UpdateRegistrationSettingsRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminSettingsUpdate];
    }

    /**
     * Validation rules for the sign-up and captcha settings. A blank Turnstile
     * secret keeps the stored one.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'registration_enabled' => ['required', 'boolean'],
            'captcha_provider' => ['required', 'string', Rule::in(CaptchaSettings::PROVIDERS)],
            'turnstile_site_key' => ['nullable', 'required_if:captcha_provider,turnstile', 'string', 'max:191'],
            'turnstile_secret_key' => ['nullable', 'string', 'max:191'],
        ];
    }

    /** Rename fields to be more clear in error messages. */
    public function attributes(): array
    {
        return [
            'registration_enabled' => 'Sign-up Enabled',
            'captcha_provider' => 'Captcha Provider',
            'turnstile_site_key' => 'Turnstile Site Key',
            'turnstile_secret_key' => 'Turnstile Secret Key',
        ];
    }
}
