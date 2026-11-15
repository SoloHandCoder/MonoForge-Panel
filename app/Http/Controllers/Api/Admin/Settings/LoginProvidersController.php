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
        ],
        'google' => [
            'enabled' => false,
            'client_id' => '',
            'client_secret_set' => false,
            'callback_url' => 'https://panel.example.com/auth/modules/google/authenticate',
        ],
    ];

    #[Endpoint('Get login providers', 'Returns the Discord and Google login settings and the callback URL to register with each provider. Client secrets are never returned.')]
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
            ];
        }

        return new JsonResponse($providers);
    }

    #[Endpoint('Update login providers', 'Updates the Discord and Google login settings. A blank client secret keeps the stored one.')]
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
            $enabled = $request->boolean($key.'.enabled');
            $clientId = mb_trim($request->string($key.'.client_id')->toString());
            $secret = mb_trim($request->string($key.'.client_secret')->toString());

            if ($enabled && $clientId === '') {
                $errors[$key.'.client_id'] = "A client ID is required to enable {$provider->label()} login.";
            }

            $secretStored = $provider->clientSecret() !== '' || (string) Setting::fetch('settings::'.$provider->settingPrefix().':client_secret') !== '';
            if ($enabled && $secret === '' && ! $secretStored) {
                $errors[$key.'.client_secret'] = "A client secret is required to enable {$provider->label()} login.";
            }

            $writes[$provider->settingPrefix().':enabled'] = $enabled ? 'true' : 'false';
            $writes[$provider->settingPrefix().':client_id'] = $clientId;
            if ($secret !== '') {
                $writes[$provider->settingPrefix().':client_secret'] = Crypt::encrypt($secret);
            }
        }

        throw_if($errors !== [], ValidationException::withMessages($errors));

        foreach ($writes as $key => $value) {
            Setting::put('settings::'.$key, $value);
        }

        return $this->returnNoContent();
    }
}
