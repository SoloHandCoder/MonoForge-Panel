<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Updates;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Exceptions\Service\MonoForge\UpdateFailedException;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Updates\ApplyUpdateRequest;
use Pterodactyl\Http\Requests\Api\Admin\Updates\GetUpdateStatusRequest;
use Pterodactyl\Http\Requests\Api\Admin\Updates\UpdateUpdateSettingsRequest;
use Pterodactyl\Models\Setting;
use Pterodactyl\Services\MonoForge\MonoForgeRelease;
use Pterodactyl\Services\MonoForge\MonoForgeUpdateService;
use Pterodactyl\Services\Settings\EnvironmentSettings;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Updates', 'Check for and install MonoForge Panel builds.')]
class UpdateController extends AdminApiController
{
    private const string MODE_SETTING = 'monoforge:update:mode';

    private const array BUILD_EXAMPLE = [
        'version' => '3f9c2ab',
        'commit' => '3f9c2ab1d4e5f60718293a4b5c6d7e8f90a1b2c3',
        'built_at' => '2026-11-15T12:00:00Z',
    ];

    private const array STATUS_EXAMPLE = [
        'mode' => 'notify',
        'mode_set_in_env' => false,
        'current_version' => '3f9c2ab',
        'current' => self::BUILD_EXAMPLE,
        'latest' => [
            ...self::BUILD_EXAMPLE,
            'version' => '9e8d7c6',
            'commit' => '9e8d7c6b5a4f30291817263544a3b2c1d0e9f8a7',
            'tag' => 'build-9e8d7c6',
            'url' => 'https://github.com/SoloHandCoder/MonoForge-Panel/releases/tag/build-9e8d7c6',
        ],
        'check' => 'available',
        'update_available' => true,
        'status' => [
            'state' => 'idle',
            'step' => 'downloading',
            'message' => 'Downloading MonoForge build 9e8d7c6.',
            'from' => '3f9c2ab',
            'to' => '9e8d7c6',
            'error' => 'The downloaded build does not match its sha256 checksum, so it was not installed.',
            'updated_at' => '2026-11-15T12:00:00Z',
        ],
    ];

    private const array ERROR_EXAMPLE = [
        'errors' => [
            [
                'code' => 'DisplayException',
                'status' => '400',
                'detail' => 'There is no newer MonoForge build to install.',
            ],
        ],
    ];

    private const array ENVIRONMENT_ONLY_EXAMPLE = [
        'errors' => [
            [
                'code' => 'DisplayException',
                'status' => '400',
                'detail' => 'MONOFORGE_UPDATE_MODE is set in .env, which this panel reads its settings from; change it there instead.',
            ],
        ],
    ];

    private const array THROTTLED_EXAMPLE = [
        'errors' => [
            [
                'code' => 'TooManyRequestsHttpException',
                'status' => '429',
                'detail' => 'Too Many Attempts.',
            ],
        ],
    ];

    public function __construct(private readonly MonoForgeUpdateService $updates) {}

    /**
     * Return the running and latest MonoForge builds and the state of the last update.
     */
    #[Endpoint('Get update status', 'Returns the update mode, the running and latest MonoForge builds and the progress of the last requested update.')]
    #[ScribeResponse(self::STATUS_EXAMPLE, description: 'Update status returned.')]
    #[ResponseField('mode', 'string', 'off, notify or auto.', enum: MonoForgeUpdateService::MODES)]
    #[ResponseField('mode_set_in_env', 'boolean', 'Whether MONOFORGE_UPDATE_MODE is set in the environment of a panel that reads its settings from the environment only; the mode can then only be changed there.')]
    #[ResponseField('current_version', 'string', 'The running build, or the app version when it is unknown.')]
    #[ResponseField('check', 'string', 'off (checks disabled), available (GitHub says the latest commit is ahead), current, or unknown (the comparison could not be fetched; nothing is installed automatically).', enum: ['off', 'available', 'current', 'unknown'])]
    #[ResponseField('update_available', 'boolean', 'Whether the latest build should replace the running one.')]
    #[ResponseField('status', 'object', 'The last requested or applied update.')]
    #[ResponseField('current', 'object', 'The running build, or null when the panel has no version.json.', nullable: true)]
    #[ResponseField('current.version', 'string')]
    #[ResponseField('current.commit', 'string')]
    #[ResponseField('latest.version', 'string')]
    #[ResponseField('latest.commit', 'string')]
    #[ResponseField('latest.tag', 'string')]
    #[ResponseField('latest.url', 'string')]
    #[ResponseField('current.built_at', 'string', nullable: true)]
    #[ResponseField('latest', 'object', 'The latest build on GitHub, or null when it is unknown or update checks are off.', nullable: true)]
    #[ResponseField('latest.built_at', 'string', nullable: true)]
    #[ResponseField('status.state', 'string', 'idle, queued, running, success or failed.', enum: ['idle', 'queued', 'running', 'success', 'failed'])]
    #[ResponseField('status.step', 'string', nullable: true)]
    #[ResponseField('status.message', 'string', nullable: true)]
    #[ResponseField('status.from', 'string', nullable: true)]
    #[ResponseField('status.to', 'string', nullable: true)]
    #[ResponseField('status.error', 'string', nullable: true)]
    #[ResponseField('status.updated_at', 'string', nullable: true)]
    public function status(GetUpdateStatusRequest $request): JsonResponse
    {
        return new JsonResponse($this->payload());
    }

    /**
     * Ask GitHub for the latest MonoForge build now instead of waiting for the cached result.
     */
    #[Endpoint('Check for updates', 'Looks up the latest MonoForge build on GitHub right away, bypassing the 30 minute cache, and returns the same payload as the update status. Limited to 6 checks a minute.')]
    #[ScribeResponse(self::STATUS_EXAMPLE, description: 'Update status returned after a fresh check.')]
    #[ScribeResponse(self::THROTTLED_EXAMPLE, status: 429, description: 'Too many checks; try again in a minute.')]
    #[ResponseField('mode', 'string', 'off, notify or auto.', enum: MonoForgeUpdateService::MODES)]
    #[ResponseField('mode_set_in_env', 'boolean', 'Whether MONOFORGE_UPDATE_MODE is set in the environment of a panel that reads its settings from the environment only; the mode can then only be changed there.')]
    #[ResponseField('current_version', 'string', 'The running build, or the app version when it is unknown.')]
    #[ResponseField('check', 'string', 'off (checks disabled), available (GitHub says the latest commit is ahead), current, or unknown (the comparison could not be fetched; nothing is installed automatically).', enum: ['off', 'available', 'current', 'unknown'])]
    #[ResponseField('update_available', 'boolean', 'Whether the latest build should replace the running one.')]
    #[ResponseField('status', 'object', 'The last requested or applied update.')]
    #[ResponseField('current', 'object', 'The running build, or null when the panel has no version.json.', nullable: true)]
    #[ResponseField('current.version', 'string')]
    #[ResponseField('current.commit', 'string')]
    #[ResponseField('latest.version', 'string')]
    #[ResponseField('latest.commit', 'string')]
    #[ResponseField('latest.tag', 'string')]
    #[ResponseField('latest.url', 'string')]
    #[ResponseField('current.built_at', 'string', nullable: true)]
    #[ResponseField('latest', 'object', 'The latest build on GitHub, or null when it is unknown or update checks are off.', nullable: true)]
    #[ResponseField('latest.built_at', 'string', nullable: true)]
    #[ResponseField('status.state', 'string', 'idle, queued, running, success or failed.', enum: ['idle', 'queued', 'running', 'success', 'failed'])]
    #[ResponseField('status.step', 'string', nullable: true)]
    #[ResponseField('status.message', 'string', nullable: true)]
    #[ResponseField('status.from', 'string', nullable: true)]
    #[ResponseField('status.to', 'string', nullable: true)]
    #[ResponseField('status.error', 'string', nullable: true)]
    #[ResponseField('status.updated_at', 'string', nullable: true)]
    public function check(GetUpdateStatusRequest $request): JsonResponse
    {
        if ($this->updates->mode() !== 'off') {
            $this->updates->latest(fresh: true);
        }

        return new JsonResponse($this->payload());
    }

    /**
     * Queue the latest MonoForge build for installation by the scheduler.
     */
    #[Endpoint('Apply update', 'Queues the latest MonoForge build. The panel scheduler installs it within a minute: it enters maintenance mode, backs up the code and database, copies the build into place and runs migrations.')]
    #[ScribeResponse(self::STATUS_EXAMPLE, status: 202, description: 'Update queued.')]
    #[ScribeResponse(self::ERROR_EXAMPLE, status: 400, description: 'Updates are off, one is already running or there is nothing newer to install.')]
    #[ResponseField('mode', 'string', 'off, notify or auto.', enum: MonoForgeUpdateService::MODES)]
    #[ResponseField('mode_set_in_env', 'boolean', 'Whether MONOFORGE_UPDATE_MODE is set in the environment of a panel that reads its settings from the environment only; the mode can then only be changed there.')]
    #[ResponseField('current_version', 'string', 'The running build, or the app version when it is unknown.')]
    #[ResponseField('check', 'string', 'off (checks disabled), available (GitHub says the latest commit is ahead), current, or unknown (the comparison could not be fetched; nothing is installed automatically).', enum: ['off', 'available', 'current', 'unknown'])]
    #[ResponseField('update_available', 'boolean', 'Whether the latest build should replace the running one.')]
    #[ResponseField('status', 'object', 'The last requested or applied update.')]
    #[ResponseField('current', 'object', 'The running build, or null when the panel has no version.json.', nullable: true)]
    #[ResponseField('current.version', 'string')]
    #[ResponseField('current.commit', 'string')]
    #[ResponseField('latest.version', 'string')]
    #[ResponseField('latest.commit', 'string')]
    #[ResponseField('latest.tag', 'string')]
    #[ResponseField('latest.url', 'string')]
    #[ResponseField('current.built_at', 'string', nullable: true)]
    #[ResponseField('latest', 'object', 'The latest build on GitHub, or null when it is unknown or update checks are off.', nullable: true)]
    #[ResponseField('latest.built_at', 'string', nullable: true)]
    #[ResponseField('status.state', 'string', 'idle, queued, running, success or failed.', enum: ['idle', 'queued', 'running', 'success', 'failed'])]
    #[ResponseField('status.step', 'string', nullable: true)]
    #[ResponseField('status.message', 'string', nullable: true)]
    #[ResponseField('status.from', 'string', nullable: true)]
    #[ResponseField('status.to', 'string', nullable: true)]
    #[ResponseField('status.error', 'string', nullable: true)]
    #[ResponseField('status.updated_at', 'string', nullable: true)]
    public function apply(ApplyUpdateRequest $request): JsonResponse
    {
        try {
            $this->updates->request();
        } catch (UpdateFailedException $updateFailedException) {
            throw new DisplayException($updateFailedException->getMessage(), $updateFailedException);
        }

        return new JsonResponse($this->payload(), Response::HTTP_ACCEPTED);
    }

    /**
     * Change how the panel checks for and installs MonoForge builds.
     */
    #[Endpoint('Update update settings', 'Sets the update mode: off, notify (check daily) or auto (check hourly and install new builds).')]
    #[ScribeResponse(status: 204, description: 'Update mode saved.')]
    #[ScribeResponse(self::ENVIRONMENT_ONLY_EXAMPLE, status: 400, description: 'MONOFORGE_UPDATE_MODE is set in the environment of a panel that reads its settings from the environment only.')]
    public function settings(UpdateUpdateSettingsRequest $request): Response
    {
        throw_if(EnvironmentSettings::locked(self::MODE_SETTING), DisplayException::class, 'MONOFORGE_UPDATE_MODE is set in .env, which this panel reads its settings from; change it there instead.');

        $mode = $request->string('mode')->toString();
        Setting::put('settings::'.self::MODE_SETTING, $mode);
        config(['monoforge.update.mode' => $mode]);

        if ($mode === 'off') {
            $this->updates->cancelPending();
        }

        return $this->returnNoContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $mode = $this->updates->mode();
        $latest = $mode === 'off' ? null : $this->updates->latest();

        return [
            'mode' => $mode,
            'mode_set_in_env' => EnvironmentSettings::locked(self::MODE_SETTING),
            'current_version' => $this->updates->currentVersion(),
            'current' => $this->updates->current()?->toArray(),
            'latest' => $latest instanceof MonoForgeRelease
                ? [...$latest->build->toArray(), 'tag' => $latest->tag, 'url' => $latest->pageUrl()]
                : null,
            'check' => $mode === 'off' ? 'off' : $this->updates->updateCheck($latest),
            'update_available' => $this->updates->isUpdateAvailable($latest),
            'status' => $this->updates->status(),
        ];
    }
}
