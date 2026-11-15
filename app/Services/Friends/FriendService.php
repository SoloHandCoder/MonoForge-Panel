<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Friends;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\FriendRequest;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Notices\NoticeService;

/**
 * Friend requests and friendships.
 *
 * Nothing here tells a sender whether the username or email they typed belongs to anyone: a request
 * is stored for whatever they typed, it shows as pending until it is accepted or they cancel it, and
 * a declined request looks the same to them as one nobody received.
 */
class FriendService
{
    public const string SENT_MESSAGE = "If that user exists, they'll get a request.";

    public function __construct(private readonly NoticeService $notices) {}

    /**
     * Send a friend request to the user with this username or email, if there is one.
     *
     * @throws DisplayException when the sender has too many requests waiting
     */
    public function request(User $sender, string $identifier): void
    {
        $identifier = mb_strtolower(mb_trim($identifier));
        $recipient = $this->find($identifier);

        // Asking yourself, or someone you are already friends with, changes nothing; the sender
        // knows both answers already, so skipping them leaks nothing.
        if ($recipient instanceof User && ($recipient->id === $sender->id || $this->areFriends($sender, $recipient))) {
            return;
        }

        if ($sender->sentFriendRequests()->where('identifier', $identifier)->exists()) {
            return;
        }

        throw_if(
            $sender->sentFriendRequests()->count() >= FriendRequest::MAX_PENDING,
            DisplayException::class,
            'You have too many friend requests waiting. Cancel some before sending more.',
        );

        // The other user asked first: this answers their request, which the sender can see.
        if ($recipient instanceof User) {
            $theirs = $recipient->sentFriendRequests()->where('recipient_id', $sender->id)->open()->first();
            if ($theirs instanceof FriendRequest) {
                $this->accept($theirs);

                return;
            }
        }

        // Someone who declined this sender before is not asked again under another name.
        $declined = $recipient instanceof User && $sender->sentFriendRequests()
            ->where('recipient_id', $recipient->id)
            ->whereNotNull('declined_at')
            ->exists();

        try {
            $request = $sender->sentFriendRequests()->create([
                'recipient_id' => $recipient?->id,
                'identifier' => $identifier,
                'declined_at' => $declined ? now() : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        Activity::event('user:friend.request')
            ->subject($sender)
            ->property('identifier', $identifier)
            ->log();

        if ($recipient instanceof User && $request->declined_at === null) {
            $this->notices->notify(
                $recipient,
                'info',
                'Friend request',
                "{$sender->username} would like to add you as a friend.",
                $this->noticeKind($sender),
                'Review',
                '/account/friends',
            );
        }
    }

    /**
     * Accept a request: the two users become friends and every request between them is closed.
     */
    public function accept(FriendRequest $request): void
    {
        $sender = $request->sender;
        $recipient = $request->recipient;
        if (! $recipient instanceof User) {
            return;
        }

        DB::transaction(function () use ($sender, $recipient): void {
            $now = now();
            DB::table('friendships')->insertOrIgnore([
                ['user_id' => $sender->id, 'friend_id' => $recipient->id, 'created_at' => $now, 'updated_at' => $now],
                ['user_id' => $recipient->id, 'friend_id' => $sender->id, 'created_at' => $now, 'updated_at' => $now],
            ]);

            FriendRequest::query()
                ->where(fn (Builder $query): Builder => $query->where('sender_id', $sender->id)->where('recipient_id', $recipient->id))
                ->orWhere(fn (Builder $query): Builder => $query->where('sender_id', $recipient->id)->where('recipient_id', $sender->id))
                ->delete();
        });

        $this->markNoticeRead($recipient, $sender);
        $this->markNoticeRead($sender, $recipient);

        Activity::event('user:friend.accept')
            ->subject($recipient)
            ->property('username', $sender->username)
            ->log();

        $this->notices->notify(
            $sender,
            'success',
            'Friend request accepted',
            "{$recipient->username} accepted your friend request.",
            'friend.accepted.'.$recipient->id,
            'Friends',
            '/account/friends',
        );
    }

    /**
     * Decline a request. The sender still sees it waiting, as they would if nobody had it.
     */
    public function decline(FriendRequest $request): void
    {
        $request->forceFill(['declined_at' => now()])->save();

        $recipient = $request->recipient;
        if ($recipient instanceof User) {
            $this->markNoticeRead($recipient, $request->sender);
        }
    }

    /**
     * Withdraw a request the user sent.
     */
    public function cancel(FriendRequest $request): void
    {
        $request->delete();

        $recipient = $request->recipient;
        if ($recipient instanceof User && ! $this->hasOpenRequest($request->sender, $recipient)) {
            $this->markNoticeRead($recipient, $request->sender);
        }
    }

    /**
     * End a friendship, for both users. Subuser access already given is left alone.
     */
    public function remove(User $user, User $friend): void
    {
        DB::table('friendships')
            ->where(fn (QueryBuilder $query): QueryBuilder => $query->where('user_id', $user->id)->where('friend_id', $friend->id))
            ->orWhere(fn (QueryBuilder $query): QueryBuilder => $query->where('user_id', $friend->id)->where('friend_id', $user->id))
            ->delete();

        Activity::event('user:friend.remove')
            ->subject($user)
            ->property('username', $friend->username)
            ->log();
    }

    public function areFriends(User $user, User $other): bool
    {
        return $user->friends()->whereKey($other->id)->exists();
    }

    private function noticeKind(User $sender): string
    {
        return 'friend.request.'.$sender->id;
    }

    private function find(string $identifier): ?User
    {
        if ($identifier === '') {
            return null;
        }

        $column = str_contains($identifier, '@') ? 'email' : 'username';

        return User::query()->where($column, $identifier)->first();
    }

    private function hasOpenRequest(User $sender, User $recipient): bool
    {
        return $sender->sentFriendRequests()->where('recipient_id', $recipient->id)->open()->exists();
    }

    private function markNoticeRead(User $user, User $from): void
    {
        $user->notices()->unread()->where('kind', $this->noticeKind($from))->update(['read_at' => now()]);
    }
}
