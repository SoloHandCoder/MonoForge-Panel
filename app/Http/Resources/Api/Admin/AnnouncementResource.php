<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Admin;

use Illuminate\Http\Request;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Models\Announcement;

/**
 * @extends AdminResource<Announcement>
 */
#[ResponseField('level', example: 'warning', enum: ['info', 'success', 'warning', 'danger'])]
#[ResponseField('status', example: 'active', enum: ['active', 'scheduled', 'ended'])]
#[ResponseField('starts_at', example: '2026-11-15T18:00:00+00:00', nullable: true)]
#[ResponseField('ends_at', example: '2026-11-15T20:00:00+00:00', nullable: true)]
class AnnouncementResource extends AdminResource
{
    public function resourceName(): string
    {
        return Announcement::RESOURCE_NAME;
    }

    public function toArray(Request $request): array
    {
        $announcement = $this->resource;

        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'level' => $announcement->level->value,
            'starts_at' => $announcement->starts_at?->toAtomString(),
            'ends_at' => $announcement->ends_at?->toAtomString(),
            'dismissible' => $announcement->dismissible,
            'status' => $announcement->status(),
            'created_at' => $this->formatTimestamp($announcement->created_at),
            'updated_at' => $this->formatTimestamp($announcement->updated_at),
        ];
    }
}
