<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Client;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Pterodactyl\Models\Announcement;
use Pterodactyl\Models\UserNotice;

/**
 * The notices the panel shows above its pages: the active announcements, and the user's unread notices.
 *
 * @extends ClientResource<array{announcements: Collection<int, Announcement>, notices: Collection<int, UserNotice>}>
 */
class NoticeFeedResource extends ClientResource
{
    public const string RESOURCE_NAME = 'notice_feed';

    public function resourceName(): string
    {
        return self::RESOURCE_NAME;
    }

    public function toArray(Request $request): array
    {
        return [
            'announcements' => $this->resource['announcements']->map(fn (Announcement $announcement): array => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'level' => $announcement->level->value,
                'dismissible' => $announcement->dismissible,
                'starts_at' => $announcement->starts_at?->toAtomString(),
                'ends_at' => $announcement->ends_at?->toAtomString(),
                'updated_at' => $this->formatTimestamp($announcement->updated_at),
            ])->values()->all(),
            'notices' => $this->resource['notices']->map(fn (UserNotice $notice): array => [
                'id' => $notice->id,
                'level' => $notice->level->value,
                'title' => $notice->title,
                'body' => $notice->body,
                'action_label' => $notice->action_label,
                'action_url' => $notice->action_url,
                'kind' => $notice->kind,
                'created_at' => $this->formatTimestamp($notice->created_at),
            ])->values()->all(),
        ];
    }
}
