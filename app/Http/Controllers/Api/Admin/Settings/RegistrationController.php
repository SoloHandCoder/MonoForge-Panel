<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Settings;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Settings\GetSettingsRequest;
use Pterodactyl\Http\Requests\Api\Admin\Settings\UpdateRegistrationSettingsRequest;
use Pterodactyl\Models\Setting;
use Pterodactyl\Services\Captcha\CaptchaSettings;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Settings', 'View and update global panel settings.')]
class RegistrationController extends AdminApiController
{
    private const array SETTINGS_EXAMPLE = [
        'registration_enabled' => false,
        'captcha_provider' => 'turnstile',
        'turnstile_site_key' => '0x4AAAAAAA',
        'turnstile_secret_key_set' => true,
        'load_environment_only' => false,
    ];

    /**
     * Return the sign-up and captcha settings. Secrets are never returned, only
     * whether one is stored.
     */
    #[Endpoint('Get registration settings', 'Returns whether sign-up is enabled and which captcha protects the sign-in forms.')]
    #[ScribeResponse(self::SETTINGS_EXAMPLE, description: 'Registration settings returned.')]
    public function index(GetSettingsRequest $request): JsonResponse
    {
        return new JsonResponse([
            'registration_enabled' => config()->boolean('monoforge.auth.registration.enabled'),
            'captcha_provider' => CaptchaSettings::provider(),
            'turnstile_site_key' => config()->string('monoforge.captcha.turnstile.site_key'),
            'turnstile_secret_key_set' => $this->turnstileSecretSet(),
            'load_environment_only' => config()->boolean('pterodactyl.load_environment_only'),
        ]);
    }

    /**
     * Update the sign-up and captcha settings.
     *
     * @throws ValidationException
     */
    #[Endpoint('Update registration settings', 'Turns sign-up on or off and sets the captcha provider and its Cloudflare Turnstile keys.')]
    #[ScribeResponse(status: 204, description: 'Registration settings updated.')]
    public function update(UpdateRegistrationSettingsRequest $request, Kernel $kernel): Response
    {
        $provider = $request->string('captcha_provider')->toString();
        $secret = $request->string('turnstile_secret_key')->trim()->toString();

        if ($provider === CaptchaSettings::TURNSTILE && $secret === '' && ! $this->turnstileSecretSet()) {
            throw ValidationException::withMessages([
                'turnstile_secret_key' => 'A Turnstile secret key is required to use Cloudflare Turnstile.',
            ]);
        }

        Setting::put('settings::monoforge:auth:registration:enabled', $request->boolean('registration_enabled') ? 'true' : 'false');
        Setting::put('settings::monoforge:captcha:provider', $provider);
        // The legacy switch follows, so the Advanced tab shows the same state.
        Setting::put('settings::recaptcha:enabled', $provider === CaptchaSettings::RECAPTCHA ? 'true' : 'false');

        if ($request->has('turnstile_site_key')) {
            Setting::put('settings::monoforge:captcha:turnstile:site_key', $request->string('turnstile_site_key')->trim()->toString());
        }

        if ($secret !== '') {
            Setting::put('settings::monoforge:captcha:turnstile:secret_key', Crypt::encrypt($secret));
        }

        $kernel->call('queue:restart');

        return $this->returnNoContent();
    }

    private function turnstileSecretSet(): bool
    {
        return config()->string('monoforge.captcha.turnstile.secret_key') !== '';
    }
}
