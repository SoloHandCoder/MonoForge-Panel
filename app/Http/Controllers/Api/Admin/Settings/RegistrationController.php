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
use Pterodactyl\Services\Settings\EnvironmentSettings;

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
        'set_in_env' => ['registration_enabled' => false, 'captcha_provider' => false, 'turnstile_site_key' => false, 'turnstile_secret_key' => false],
    ];

    /** The settings behind each field. */
    private const array KEYS = [
        'registration_enabled' => 'monoforge:auth:registration:enabled',
        'captcha_provider' => 'monoforge:captcha:provider',
        'turnstile_site_key' => 'monoforge:captcha:turnstile:site_key',
        'turnstile_secret_key' => 'monoforge:captcha:turnstile:secret_key',
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
            'set_in_env' => array_map(EnvironmentSettings::locked(...), self::KEYS),
        ]);
    }

    /**
     * Update the sign-up and captcha settings.
     *
     * @throws ValidationException
     */
    #[Endpoint('Update registration settings', 'Turns sign-up on or off and sets the captcha provider and its Cloudflare Turnstile keys. Fields set in .env (see set_in_env) are left as they are.')]
    #[ScribeResponse(status: 204, description: 'Registration settings updated.')]
    public function update(UpdateRegistrationSettingsRequest $request, Kernel $kernel): Response
    {
        $locked = array_map(EnvironmentSettings::locked(...), self::KEYS);

        // A field set in .env keeps the environment's value, which is what applies.
        $provider = $locked['captcha_provider'] ? CaptchaSettings::provider() : $request->string('captcha_provider')->toString();
        $secret = $locked['turnstile_secret_key'] ? '' : $request->string('turnstile_secret_key')->trim()->toString();

        if ($provider === CaptchaSettings::TURNSTILE && $secret === '' && ! $this->turnstileSecretSet()) {
            throw ValidationException::withMessages([
                'turnstile_secret_key' => 'A Turnstile secret key is required to use Cloudflare Turnstile.',
            ]);
        }

        if (! $locked['registration_enabled']) {
            Setting::put('settings::'.self::KEYS['registration_enabled'], $request->boolean('registration_enabled') ? 'true' : 'false');
        }

        if (! $locked['captcha_provider']) {
            Setting::put('settings::'.self::KEYS['captcha_provider'], $provider);
        }

        // The legacy switch follows, so the Advanced tab shows the same state. It is an upstream
        // setting, which environment-only mode takes from the environment.
        if (! EnvironmentSettings::environmentOnly()) {
            Setting::put('settings::recaptcha:enabled', $provider === CaptchaSettings::RECAPTCHA ? 'true' : 'false');
        }

        if ($request->has('turnstile_site_key') && ! $locked['turnstile_site_key']) {
            Setting::put('settings::'.self::KEYS['turnstile_site_key'], $request->string('turnstile_site_key')->trim()->toString());
        }

        if ($secret !== '') {
            Setting::put('settings::'.self::KEYS['turnstile_secret_key'], Crypt::encrypt($secret));
        }

        $kernel->call('queue:restart');

        return $this->returnNoContent();
    }

    private function turnstileSecretSet(): bool
    {
        return config()->string('monoforge.captcha.turnstile.secret_key') !== '';
    }
}
