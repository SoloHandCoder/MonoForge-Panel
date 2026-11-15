<?php

declare(strict_types=1);

namespace Pterodactyl\Actions\Users;

use Pterodactyl\Contracts\Users\DeletesUsers;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Models\Support\SupportAttachment;
use Pterodactyl\Models\Support\SupportMessage;
use Pterodactyl\Models\Support\SupportTicket;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Support\SupportAttachmentStore;

final readonly class DeleteUser implements DeletesUsers
{
    public function __construct(private SupportAttachmentStore $attachments) {}

    public function delete(User $user): ?bool
    {
        if ($user->servers()->exists()) {
            throw new DisplayException(trans('admin/user.exceptions.user_has_servers'));
        }

        // The user's support tickets go with them (database cascade, no model events), so the
        // image files of those tickets are removed here once the user is gone.
        $paths = array_values(SupportAttachment::query()
            ->whereIn('message_id', SupportMessage::query()->select('id')->whereIn('ticket_id', SupportTicket::query()->select('id')->where('user_id', $user->id)))
            ->get(['path'])
            ->map(fn (SupportAttachment $attachment): string => $attachment->path)
            ->all());

        $deleted = $user->delete();

        if ($deleted === true) {
            $this->attachments->delete($paths);
        }

        return $deleted;
    }
}
