<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Assistant\Tools\Admin;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Pterodactyl\Enum\NoticeLevel;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Announcement;
use Pterodactyl\Services\Assistant\ToolContext;
use Pterodactyl\Services\Assistant\ToolResult;

/**
 * Publishes an announcement to every user, under the rules of the admin announcements form.
 */
final class CreateAnnouncementTool extends AdminTool
{
    public function name(): string
    {
        return 'create_announcement';
    }

    public function description(): string
    {
        return 'Publish an announcement banner that every user sees. Optionally schedule it with starts_at and ends_at. The admin is asked to confirm first.';
    }

    public function parameters(): array
    {
        return $this->schema([
            'title' => ['type' => 'string', 'maxLength' => 191],
            'body' => ['type' => 'string', 'maxLength' => 5000],
            'level' => ['type' => 'string', 'enum' => NoticeLevel::values(), 'description' => 'Defaults to "info".'],
            'starts_at' => ['type' => 'string', 'description' => 'Optional ISO 8601 date and time it starts showing.'],
            'ends_at' => ['type' => 'string', 'description' => 'Optional ISO 8601 date and time it stops showing.'],
            'dismissible' => ['type' => 'boolean', 'description' => 'Whether users can dismiss it. Defaults to true.'],
        ], ['title', 'body']);
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return true;
    }

    public function describeCall(array $arguments): string
    {
        return sprintf('Publish the announcement "%s"', $this->labelArgument($arguments, 'title'));
    }

    protected function permissions(): array
    {
        return [Permissions::AdminAnnouncementsCreate];
    }

    protected function run(array $arguments, ToolContext $context): ToolResult
    {
        $input = [
            'title' => mb_trim($this->string($arguments, 'title', null, 191)),
            'body' => mb_trim($this->string($arguments, 'body', null, 5000)),
            'level' => $this->string($arguments, 'level', NoticeLevel::Info->value, 16),
            'starts_at' => $this->string($arguments, 'starts_at', '', 64),
            'ends_at' => $this->string($arguments, 'ends_at', '', 64),
        ];
        $input['starts_at'] = $input['starts_at'] === '' ? null : $input['starts_at'];
        $input['ends_at'] = $input['ends_at'] === '' ? null : $input['ends_at'];

        Validator::make($input, [
            'title' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:5000'],
            'level' => ['required', 'string', Rule::enum(NoticeLevel::class)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', ...($input['starts_at'] !== null ? ['after:starts_at'] : [])],
        ])->validate();

        $announcement = Announcement::query()->create([
            'title' => $input['title'],
            'body' => $input['body'],
            'level' => NoticeLevel::from($input['level']),
            'starts_at' => $input['starts_at'],
            'ends_at' => $input['ends_at'],
            'dismissible' => $this->boolean($arguments, 'dismissible', true),
        ]);

        Activity::event('admin:announcement.create')->property('title', $announcement->title)->log();

        return new ToolResult(sprintf('Published the announcement "%s" (id %d).', $announcement->title, $announcement->id), ['id' => $announcement->id, 'title' => $announcement->title]);
    }
}
