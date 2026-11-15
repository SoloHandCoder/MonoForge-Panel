<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Enum\OAuthProvider;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Settings\GetSettingsRequest;
use Pterodactyl\Http\Requests\Api\Admin\Settings\UpdateLoginProvidersRequest;
use Pterodactyl\Models\Setting;
use Pterodactyl\Services\Settings\EnvironmentSettings;

/**
 * The Discord and Google apps used for "Continue with Discord/Google". Client secrets are
 * write-only: they are stored encrypted and only reported as set or not.
 */
#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Settings', 'View and update global panel settings.')]
class LoginProvidersController extends AdminApiController
{
    private const array EXAMPLE = [
        'discord' => [
            'enabled' => true,
            'client_id' => '123456789012345678',
            'client_secret_set' => true,
            'callback_url' => 'https://panel.example.com/auth/modules/discord/authenticate',
            'set_in_env' => ['enabled' => false, 'client_id' => false, 'client_secret' => false],
        ],
        'google' => [
            'enabled' => false,
            'client_id' => '',
            'client_secret_set' => false,
            'callback_url' => 'https://panel.example.com/auth/modules/google/authenticate',
            'set_in_env' => ['enabled' => false, 'client_id' => false, 'client_secret' => false],
        ],
    ];

    #[Endpoint('Get login providers', 'Returns the Discord and Google login settings and the callback URL to register with each provider. Client secrets are never returned. set_in_env says which fields come from .env in a panel that reads its settings from the environment only; those cannot be changed here.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Login provider settings returned.')]
    public function index(GetSettingsRequest $request): JsonResponse
    {
        $providers = [];
        foreach (OAuthProvider::cases() as $provider) {
            $providers[$provider->value] = [
                'enabled' => filter_var(config('monoforge.auth.'.$provider->value.'.enabled'), FILTER_VALIDATE_BOOL),
                'client_id' => $provider->clientId(),
                'client_secret_set' => $provider->clientSecret() !== '',
                'callback_url' => $provider->callbackUrl(),
                'set_in_env' => [
                    'enabled' => EnvironmentSettings::locked($provider->settingPrefix().':enabled'),
                    'client_id' => EnvironmentSettings::locked($provider->settingPrefix().':client_id'),
                    'client_secret' => EnvironmentSettings::locked($provider->settingPrefix().':client_secret'),
                ],
            ];
        }

        return new JsonResponse($providers);
    }

    #[Endpoint('Update login providers', 'Updates the Discord and Google login settings. A blank client secret keeps the stored one. Fields set in .env (see set_in_env) are left as they are.')]
    #[BodyParam('discord.enabled', 'boolean', 'Whether users can sign in with Discord.', required: true, example: true)]
    #[BodyParam('discord.client_id', 'string', 'The Discord application client ID.', required: false, example: '123456789012345678', nullable: true)]
    #[BodyParam('discord.client_secret', 'string', 'A new Discord client secret, or blank to keep the stored one.', required: false, example: null, nullable: true)]
    #[BodyParam('google.enabled', 'boolean', 'Whether users can sign in with Google.', required: true, example: false)]
    #[BodyParam('google.client_id', 'string', 'The Google OAuth client ID.', required: false, example: '1234-abc.apps.googleusercontent.com', nullable: true)]
    #[BodyParam('google.client_secret', 'string', 'A new Google client secret, or blank to keep the stored one.', required: false, example: null, nullable: true)]
    #[ScribeResponse(status: 204, description: 'Login provider settings updated.')]
    public function update(UpdateLoginProvidersRequest $request): Response
    {
        $errors = [];
        $writes = [];

        foreach (OAuthProvider::cases() as $provider) {
            $key = $provider->value;
            $prefix = $provider->settingPrefix();
            $locked = fn (string $field): bool => EnvironmentSettings::locked($prefix.':'.$field);

            // A field set in .env keeps the environment's value, which is what applies.
            $enabled = $locked('enabled') ? filter_var(config('monoforge.auth.'.$key.'.enabled'), FILTER_VALIDATE_BOOL) : $request->boolean($key.'.enabled');
            $clientId = $locked('client_id') ? $provider->clientId() : mb_trim($request->string($key.'.client_id')->toString());
            $secret = $locked('client_secret') ? '' : mb_trim($request->string($key.'.client_secret')->toString());

            if ($enabled && $clientId === '') {
                $errors[$key.'.client_id'] = "A client ID is required to enable {$provider->label()} login.";
            }

            $secretStored = $provider->clientSecret() !== '' || (string) Setting::fetch('settings::'.$prefix.':client_secret') !== '';
            if ($enabled && $secret === '' && ! $secretStored) {
                $errors[$key.'.client_secret'] = "A client secret is required to enable {$provider->label()} login.";
            }

            if (! $locked('enabled')) {
                $writes[$prefix.':enabled'] = $enabled ? 'true' : 'false';
            }

            if (! $locked('client_id')) {
                $writes[$prefix.':client_id'] = $clientId;
            }

            if ($secret !== '') {
                $writes[$prefix.':client_secret'] = Crypt::encrypt($secret);
            }
        }

        throw_if($errors !== [], ValidationException::withMessages($errors));

        foreach ($writes as $key => $value) {
            Setting::put('settings::'.$key, $value);
        }

        return $this->returnNoContent();
    }
}
