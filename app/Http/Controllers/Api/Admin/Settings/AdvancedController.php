<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Settings;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Settings\UpdateAdvancedSettingsRequest;
use Pterodactyl\Models\Setting;
use Pterodactyl\Services\Captcha\CaptchaSettings;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Settings', 'View and update global panel settings.')]
class AdvancedController extends AdminApiController
{
    /**
     * Update advanced settings.
     */
    #[Endpoint('Update advanced settings', 'Updates reCAPTCHA, HTTP client timeout, client allocation feature and hidden IP address settings.')]
    #[ScribeResponse(status: 204, description: 'Advanced settings updated.')]
    public function __invoke(UpdateAdvancedSettingsRequest $request, Kernel $kernel): Response
    {
        $values = $request->normalize();
        foreach ($values as $key => $value) {
            Setting::put('settings::'.$key, ($value) === null ? null : (string) $value);
        }

        // Turning reCAPTCHA on picks it as the captcha provider; turning it off only
        // clears the provider while reCAPTCHA is the one in use (Turnstile stays).
        if ($values['recaptcha:enabled'] === 'true') {
            Setting::put('settings::monoforge:captcha:provider', CaptchaSettings::RECAPTCHA);
        } elseif (CaptchaSettings::provider() === CaptchaSettings::RECAPTCHA) {
            Setting::put('settings::monoforge:captcha:provider', CaptchaSettings::NONE);
        }

        $kernel->call('queue:restart');

        return $this->returnNoContent();
    }
}
