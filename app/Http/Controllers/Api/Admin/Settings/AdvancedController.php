<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Settings;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Settings\UpdateAdvancedSettingsRequest;
use Pterodactyl\Models\Setting;
use Pterodactyl\Services\Captcha\CaptchaSettings;
use Pterodactyl\Services\Settings\EnvironmentSettings;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Settings', 'View and update global panel settings.')]
class AdvancedController extends AdminApiController
{
    private const string HIDDEN_IPS = 'monoforge:privacy:hidden_ips';

    private const string HIDDEN_IPS_LOCKED = 'MONOFORGE_HIDDEN_IPS is set in .env, which this panel reads its settings from; change it there instead.';

    private const array HIDDEN_IPS_LOCKED_EXAMPLE = [
        'errors' => [[
            'code' => 'DisplayException',
            'status' => '400',
            'detail' => self::HIDDEN_IPS_LOCKED,
        ]],
    ];

    /**
     * Update advanced settings.
     */
    #[Endpoint('Update advanced settings', 'Updates reCAPTCHA, HTTP client timeout, client allocation feature and hidden IP address settings. On a panel that reads its settings from the environment only, just the hidden IP addresses are saved, and only while MONOFORGE_HIDDEN_IPS is unset.')]
    #[ScribeResponse(status: 204, description: 'Advanced settings updated.')]
    #[ScribeResponse(self::HIDDEN_IPS_LOCKED_EXAMPLE, status: 400, description: 'MONOFORGE_HIDDEN_IPS is set in the environment of a panel that reads its settings from the environment only.')]
    public function __invoke(UpdateAdvancedSettingsRequest $request, Kernel $kernel): Response
    {
        $values = $request->normalize();

        if (EnvironmentSettings::environmentOnly()) {
            throw_if(EnvironmentSettings::locked(self::HIDDEN_IPS), DisplayException::class, self::HIDDEN_IPS_LOCKED);

            // The upstream keys come from the environment; only MonoForge's own key is stored.
            if (array_key_exists(self::HIDDEN_IPS, $values)) {
                Setting::put('settings::'.self::HIDDEN_IPS, $values[self::HIDDEN_IPS] === null ? null : (string) $values[self::HIDDEN_IPS]);
            }

            $kernel->call('queue:restart');

            return $this->returnNoContent();
        }

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
