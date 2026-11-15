<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Settings;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Settings\UpdateMailSettingsRequest;
use Pterodactyl\Models\Setting;
use Pterodactyl\Providers\SettingsServiceProvider;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Settings', 'View and update global panel settings.')]
class MailController extends AdminApiController
{
    private const array ENVIRONMENT_ONLY_EXAMPLE = [
        'errors' => [
            [
                'code' => 'DisplayException',
                'status' => '400',
                'detail' => 'This panel reads its settings from the environment only; set the MAIL_* variables in .env instead.',
            ],
        ],
    ];

    /**
     * Update mail settings.
     */
    #[Endpoint('Update mail settings', 'Selects the mail driver (SMTP, Resend or log), updates its settings and the sender, and restarts queued workers so they reload configuration. The SMTP password and Resend API key are write-only.')]
    #[ScribeResponse(status: 204, description: 'Mail settings updated.')]
    #[ScribeResponse(self::ENVIRONMENT_ONLY_EXAMPLE, status: 400, description: 'The panel reads its settings from the environment only.')]
    public function __invoke(UpdateMailSettingsRequest $request, Kernel $kernel): Response
    {
        throw_if(config('pterodactyl.load_environment_only'), DisplayException::class, 'This panel reads its settings from the environment only; set the MAIL_* variables in .env instead.');

        $values = $request->normalize();
        // "!e" clears a stored secret.
        foreach (['mail:mailers:smtp:password', 'services:resend:key'] as $secret) {
            if (Arr::get($values, $secret) === '!e') {
                $values[$secret] = '';
            }
        }

        foreach ($values as $key => $value) {
            if (in_array($key, SettingsServiceProvider::getEncryptedKeys()) && ! empty($value)) {
                $value = Crypt::encrypt($value);
            }

            Setting::put('settings::'.$key, ($value) === null ? null : (string) $value);
        }

        $kernel->call('queue:restart');

        return $this->returnNoContent();
    }
}
