<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Notices;

use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Enum\NoticeLevel;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;

/**
 * Shows in-panel notices to a user, such as a low balance warning or an upcoming suspension.
 */
class NoticeService
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * Notify a user. A notice with a `kind` replaces the user's unread notice of that kind instead
     * of stacking a second one, so a repeated check (a low balance each hour) shows one notice.
     */
    public function notify(
        User $user,
        string $level,
        string $title,
        string $body,
        ?string $kind = null,
        ?string $actionLabel = null,
        ?string $actionUrl = null,
    ): UserNotice {
        $attributes = [
            'level' => NoticeLevel::from($level),
            'title' => $title,
            'body' => $body,
            'action_label' => $actionLabel,
            'action_url' => $actionUrl,
            'kind' => $kind,
        ];

        if ($kind === null) {
            return $user->notices()->create($attributes);
        }

        return $this->connection->transaction(function () use ($user, $kind, $attributes): UserNotice {
            $existing = $user->notices()->unread()->where('kind', $kind)->lockForUpdate()->first();

            if ($existing === null) {
                return $user->notices()->create($attributes);
            }

            $existing->fill($attributes)->save();

            return $existing;
        });
    }
}
