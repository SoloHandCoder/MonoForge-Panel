<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Schedules;

use Illuminate\Validation\Rule;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Enum\SchedulePreset;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Schedules\SchedulePresets;

class StoreSchedulePresetRequest extends ViewScheduleRequest
{
    public function permission(): string
    {
        return Permissions::ScheduleCreate->value;
    }

    /**
     * The user also needs the permissions of the preset's tasks: restarting, sending the warning
     * to the console, creating backups or deleting files. An unknown preset is left to validation.
     */
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        $preset = $this->preset();
        if (! $preset instanceof SchedulePreset) {
            return true;
        }

        $server = $this->parameter('server', Server::class);
        foreach (SchedulePresets::permissions($preset, $this->all()) as $permission) {
            if (! $this->user()->can($permission->value, $server)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        $preset = $this->preset();

        return [
            'preset' => ['required', 'string', Rule::in(SchedulePreset::values())],
            ...($preset instanceof SchedulePreset ? SchedulePresets::rules($preset, $this->parameter('server', Server::class)) : []),
        ];
    }

    public function preset(): ?SchedulePreset
    {
        $preset = $this->input('preset');

        return is_string($preset) ? SchedulePreset::tryFrom($preset) : null;
    }
}
