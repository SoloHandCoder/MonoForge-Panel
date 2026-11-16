<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Enum\SchedulePreset;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseFromResource;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Schedules\StoreSchedulePresetRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Schedules\ViewScheduleRequest;
use Pterodactyl\Http\Resources\Api\Client\ScheduleResource;
use Pterodactyl\Models\Schedule;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Schedules\SchedulePresets;
use UnexpectedValueException;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Server Schedules', 'Create, update, execute, delete, and manage scheduled server tasks.')]
class SchedulePresetController extends ClientApiController
{
    public const string RESOURCE_NAME = 'schedule_presets';

    private const array PRESETS_EXAMPLE = [
        'object' => self::RESOURCE_NAME,
        'attributes' => [
            'timezone' => 'Europe/Berlin',
            'is_minecraft' => true,
            'backup_limit' => 3,
            'can_send_commands' => true,
            'presets' => [
                ['key' => 'daily_restart', 'available' => true, 'reason' => null],
                ['key' => 'backup_rotation', 'available' => true, 'reason' => null],
                ['key' => 'restart_when_empty', 'available' => true, 'reason' => null],
                ['key' => 'weekly_cleanup', 'available' => false, 'reason' => 'You need the file.delete permission.'],
            ],
        ],
    ];

    private const array ALREADY_SET_UP_ERROR = [
        'errors' => [[
            'code' => 'DisplayException',
            'status' => '400',
            'detail' => 'Daily restart is already set up on this server. Disable it first to set it up again.',
        ]],
    ];

    public function __construct(private readonly SchedulePresets $presets) {}

    /**
     * Lists the Quick setup presets and whether the viewer can set each one up.
     */
    #[Endpoint('List schedule presets', "Returns the Quick setup presets for the server's schedules (daily restart, backup rotation, restart when empty, weekly cleanup), whether the viewer can set each one up and why not, and the timezone schedules run in.")]
    #[ScribeResponse(self::PRESETS_EXAMPLE, description: 'Schedule presets returned.')]
    #[ResponseField('attributes.timezone', 'string', "The timezone schedule times are in (the panel's).", example: 'Europe/Berlin')]
    #[ResponseField('attributes.is_minecraft', 'boolean', 'The server runs Minecraft Java: restart warnings use `say` and the weekly cleanup is offered.', example: true)]
    #[ResponseField('attributes.backup_limit', 'integer', 'The most backups the server keeps; a backup rotation keeps at most this many.', example: 3)]
    #[ResponseField('attributes.can_send_commands', 'boolean', 'The viewer has control.console, which a daily restart needs to warn players first.', example: true)]
    #[ResponseField('attributes.presets', description: 'Each preset, in display order.')]
    #[ResponseField('attributes.presets[].key', 'string', 'The preset.', example: 'daily_restart', enum: ['daily_restart', 'backup_rotation', 'restart_when_empty', 'weekly_cleanup'])]
    #[ResponseField('attributes.presets[].available', 'boolean', 'The viewer can set it up on this server.', example: true)]
    #[ResponseField('attributes.presets[].reason', 'string', 'Why it cannot be set up, or null.', example: 'You need the file.delete permission.', nullable: true)]
    public function index(ViewScheduleRequest $request, Server $server): JsonResponse
    {
        $user = $request->user();

        return new JsonResponse([
            'object' => self::RESOURCE_NAME,
            'attributes' => [
                'timezone' => SchedulePresets::timezone(),
                'is_minecraft' => SchedulePresets::isMinecraft($server),
                'backup_limit' => $server->backup_limit,
                'can_send_commands' => $user->can(Permissions::ControlConsole->value, $server),
                'presets' => array_map(function (SchedulePreset $preset) use ($server, $user): array {
                    $reason = SchedulePresets::reason($preset, $server, $user);

                    return ['key' => $preset->value, 'available' => $reason === null, 'reason' => $reason];
                }, SchedulePreset::cases()),
            ],
        ]);
    }

    /**
     * Sets up a preset: creates its schedule and tasks.
     *
     * @throws DisplayException
     */
    #[Endpoint('Create schedule from preset', "Creates an ordinary, editable schedule with its tasks from a Quick setup preset, tagged with the preset. Needs schedule.create and the permissions of the preset's tasks: control.restart (and control.console to warn players) for a daily restart, backup.create for a backup rotation, control.restart for a restart when empty, file.delete for a weekly cleanup. A server has at most one active schedule per preset.")]
    #[BodyParam('preset', 'string', 'The preset: daily_restart, backup_rotation, restart_when_empty or weekly_cleanup.', required: true, example: 'daily_restart', enum: ['daily_restart', 'backup_rotation', 'restart_when_empty', 'weekly_cleanup'])]
    #[BodyParam('hour', 'integer', "Daily restart: the hour to restart at (0-23), in the panel's timezone.", required: false, example: 4)]
    #[BodyParam('minute', 'integer', 'Daily restart: the minute to restart at (0-59).', required: false, example: 0)]
    #[BodyParam('warning_minutes', 'integer', 'Daily restart: how many minutes ahead players are warned (0-15, 0 for no warning).', required: false, example: 5)]
    #[BodyParam('warning_command', 'string', 'Daily restart on a game other than Minecraft: the console command that broadcasts the warning (defaults to say).', required: false, example: 'say', nullable: true)]
    #[BodyParam('every_hours', 'integer', 'Backup rotation: hours between backups (1, 2, 3, 4, 6, 8, 12 or 24).', required: false, example: 6)]
    #[BodyParam('keep', 'integer', "Backup rotation: how many of its backups to keep, at most the server's backup limit.", required: false, example: 3)]
    #[BodyParam('hours', 'integer', 'Restart when empty: hours with nobody online before the restart (1-72).', required: false, example: 2)]
    #[BodyParam('days', 'integer', 'Weekly cleanup: logs older than this many days are deleted (1-365).', required: false, example: 7)]
    #[ResponseFromResource(ScheduleResource::class, Schedule::class, description: 'Schedule created from the preset.', resourceKey: 'server_schedule')]
    #[ScribeResponse(self::ALREADY_SET_UP_ERROR, status: 400, description: 'The preset is already set up, or not available on this server.')]
    public function store(StoreSchedulePresetRequest $request, Server $server): ScheduleResource
    {
        $preset = $request->preset() ?? throw new UnexpectedValueException('The preset passed validation but is unknown.');

        return ScheduleResource::make($this->presets->create($server, $preset, $request->validated()));
    }
}
