<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Models\Support\SupportAttachment;
use Pterodactyl\Models\User;

/**
 * Keeps one account from filling the disk with support images: at most MAX_BYTES stored per
 * user in all, and at most DAILY_FILES uploaded in any 24 hours. Staff replies skip the daily
 * count, never the size limit. Attachments are counted against the user who posted them.
 * Must run inside the transaction that stores the files: the user's row is locked so parallel
 * uploads cannot both slip under the limit.
 */
class SupportAttachmentQuota
{
    public const int MAX_BYTES = 100 * 1024 * 1024;

    public const int DAILY_FILES = 30;

    /**
     * @throws ValidationException when the files would take the user over the daily count
     */
    public function assertDailyCount(User $user, int $files, bool $staff): void
    {
        if ($files < 1) {
            return;
        }

        $this->lockUser($user);
        if ($staff) {
            return;
        }

        $today = $this->attachments($user)->where('support_attachments.created_at', '>=', CarbonImmutable::now()->subDay())->count();
        if ($today + $files > self::DAILY_FILES) {
            $left = max(0, self::DAILY_FILES - $today);

            throw ValidationException::withMessages(['attachments' => sprintf(
                'You can attach at most %d images a day. %s',
                self::DAILY_FILES,
                $left === 0 ? 'Try again tomorrow, or send the message without images.' : sprintf('You have %d left today.', $left),
            )]);
        }
    }

    /**
     * @throws ValidationException when the bytes would take the user's stored total over MAX_BYTES
     */
    public function assertStorage(User $user, int $bytes): void
    {
        if ($bytes < 1) {
            return;
        }

        $stored = (int) $this->attachments($user)->sum('support_attachments.size');
        if ($stored + $bytes > self::MAX_BYTES) {
            throw ValidationException::withMessages(['attachments' => sprintf(
                'Your support images already use %s of the %d MB allowed per account. Send the message without images. Images on tickets closed for more than %d days are removed.',
                $this->megabytes($stored),
                self::MAX_BYTES / 1024 / 1024,
                SupportAttachmentPruner::RETENTION_DAYS,
            )]);
        }
    }

    /**
     * @return Builder<SupportAttachment>
     */
    private function attachments(User $user): Builder
    {
        return SupportAttachment::query()
            ->join('support_messages', 'support_messages.id', '=', 'support_attachments.message_id')
            ->where('support_messages.user_id', $user->id);
    }

    private function lockUser(User $user): void
    {
        DB::table('users')->where('id', $user->id)->lockForUpdate()->value('id');
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 1).' MB';
    }
}
