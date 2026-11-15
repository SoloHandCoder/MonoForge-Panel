<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Enum\SupportTicketStatus;
use Pterodactyl\Models\Support\SupportAttachment;
use Pterodactyl\Models\Support\SupportMessage;

/**
 * Removes the images of tickets closed more than RETENTION_DAYS ago: the files and their rows.
 * The messages stay, and remember how many images they lost so the thread can say so.
 */
class SupportAttachmentPruner
{
    public const int RETENTION_DAYS = 180;

    public function __construct(private readonly SupportAttachmentStore $store) {}

    /** The number of attachments removed. */
    public function prune(): int
    {
        $before = CarbonImmutable::now()->subDays(self::RETENTION_DAYS);
        $pruned = 0;

        SupportAttachment::query()
            ->whereHas('message.ticket', fn (Builder $query): Builder => $query
                ->where('status', SupportTicketStatus::Closed->value)
                ->where('closed_at', '<', $before))
            ->chunkById(200, function (Collection $attachments) use (&$pruned): void {
                // Files first: should removing the rows fail, the next run tries them again.
                $this->store->delete(array_values($attachments->map(fn (SupportAttachment $attachment): string => $attachment->path)->all()));

                DB::transaction(function () use ($attachments): void {
                    SupportAttachment::query()->whereKey($attachments->modelKeys())->delete();
                    foreach ($attachments->countBy('message_id') as $messageId => $count) {
                        SupportMessage::query()->whereKey($messageId)->increment('expired_attachments', $count);
                    }
                });

                $pruned += $attachments->count();
            });

        return $pruned;
    }
}
