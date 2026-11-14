<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Announcements;

use Illuminate\Validation\Rule;
use Pterodactyl\Enum\NoticeLevel;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Http\Requests\Api\Admin\AdminApiRequest;

class StoreAnnouncementRequest extends AdminApiRequest
{
    public function permissions(): array
    {
        return [Permissions::AdminAnnouncementsCreate];
    }

    /**
     * Validation rules for an announcement; an unset start or end leaves that side of the schedule open.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:5000'],
            'level' => ['required', 'string', Rule::enum(NoticeLevel::class)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', ...($this->filled('starts_at') ? ['after:starts_at'] : [])],
            'dismissible' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The announcement's attributes from the validated input.
     *
     * @return array{title: string, body: string, level: NoticeLevel, starts_at: string|null, ends_at: string|null, dismissible: bool}
     */
    public function announcementAttributes(): array
    {
        return [
            'title' => $this->string('title')->toString(),
            'body' => $this->string('body')->toString(),
            'level' => NoticeLevel::from($this->string('level')->toString()),
            'starts_at' => $this->filled('starts_at') ? $this->string('starts_at')->toString() : null,
            'ends_at' => $this->filled('ends_at') ? $this->string('ends_at')->toString() : null,
            'dismissible' => $this->boolean('dismissible', true),
        ];
    }
}
