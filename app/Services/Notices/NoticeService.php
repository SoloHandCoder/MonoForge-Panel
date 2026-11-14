<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Notices;

use Illuminate\Database\UniqueConstraintViolationException;
use Pterodactyl\Enum\NoticeLevel;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserNotice;

/**
 * Shows in-panel notices to a user, such as a low balance warning or an upcoming suspension.
 */
class NoticeService
{
    /**
     * Notify a user. A notice with a `kind` replaces the user's unread notice of that kind instead
     * of stacking a second one, so a repeated check (a low balance each hour) shows one notice.
     *
     * An action link must be a panel path or an http(s) URL; any other link is dropped with its label.
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
        $url = ActionUrl::sanitize($actionUrl);
        $attributes = [
            'level' => NoticeLevel::from($level),
            'title' => $title,
            'body' => $body,
            'action_label' => $url === null ? null : $actionLabel,
            'action_url' => $url,
            'kind' => $kind,
        ];

        if ($kind === null) {
            return $user->notices()->create($attributes);
        }

        $existing = $this->unreadOfKind($user, $kind);

        if (! $existing instanceof UserNotice) {
            try {
                return $user->notices()->create($attributes);
            } catch (UniqueConstraintViolationException $exception) {
                // A concurrent notify created the unread notice of this kind first; update that one.
                $existing = $this->unreadOfKind($user, $kind) ?? throw $exception;
            }
        }

        $existing->fill($attributes)->save();

        return $existing;
    }

    private function unreadOfKind(User $user, string $kind): ?UserNotice
    {
        return $user->notices()->unread()->where('kind', $kind)->first();
    }
}
