<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Settings;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Settings\GetSettingsRequest;
use Pterodactyl\Http\Requests\Api\Admin\Settings\UpdatePluginSettingsRequest;
use Pterodactyl\Models\Setting;
use Pterodactyl\Services\Plugins\Sources\CurseForgeCatalog;
use Pterodactyl\Services\Settings\EnvironmentSettings;

/**
 * Settings of the one-click plugins and mods: the CurseForge API key. The key is write-only:
 * it is stored encrypted and only reported as set or not.
 */
#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Settings', 'View and update global panel settings.')]
class PluginSettingsController extends AdminApiController
{
    private const string KEY = 'monoforge:plugins:curseforge_key';

    /** @return array{curseforge_key_set: bool, set_in_env: bool} */
    #[Endpoint('Get plugin settings', 'Returns whether a CurseForge API key is set, which offers CurseForge mods and plugins next to Modrinth and Hangar. The key itself is never returned. set_in_env is true when CURSEFORGE_API_KEY in .env sets it in a panel that reads its settings from the environment only.')]
    #[ScribeResponse(['curseforge_key_set' => true, 'set_in_env' => false], description: 'Plugin settings returned.')]
    public function index(GetSettingsRequest $request): array
    {
        return [
            'curseforge_key_set' => CurseForgeCatalog::apiKey() !== '',
            'set_in_env' => EnvironmentSettings::locked(self::KEY),
        ];
    }

    #[Endpoint('Update plugin settings', 'Sets or clears the CurseForge API key. A blank key keeps the stored one. Nothing changes when the key is set in .env (see set_in_env).')]
    #[BodyParam('curseforge_key', 'string', 'A new CurseForge API key, or blank to keep the stored one.', required: false, example: null, nullable: true)]
    #[BodyParam('clear_curseforge_key', 'boolean', 'Remove the stored key.', required: false, example: false)]
    #[ScribeResponse(status: 204, description: 'Plugin settings updated.')]
    public function update(UpdatePluginSettingsRequest $request): Response
    {
        if (! EnvironmentSettings::locked(self::KEY)) {
            $key = mb_trim($request->string('curseforge_key')->toString());

            if ($request->boolean('clear_curseforge_key')) {
                Setting::put('settings::'.self::KEY, '');
                config()->set('monoforge.plugins.curseforge_key', '');
            } elseif ($key !== '') {
                Setting::put('settings::'.self::KEY, Crypt::encrypt($key));
                config()->set('monoforge.plugins.curseforge_key', $key);
            }
        }

        return $this->returnNoContent();
    }
}
