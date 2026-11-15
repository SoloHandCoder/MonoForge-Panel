<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Client;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Pterodactyl\Models\FriendRequest;
use Pterodactyl\Models\User;

/**
 * The user's friends, the requests waiting for their answer, and the requests they sent.
 *
 * A sent request shows only what the sender typed, never who (if anyone) it matched.
 *
 * @extends ClientResource<array{friends: Collection<int, User>, incoming: Collection<int, FriendRequest>, outgoing: Collection<int, FriendRequest>}>
 */
class FriendListResource extends ClientResource
{
    public const string RESOURCE_NAME = 'friend_list';

    public function resourceName(): string
    {
        return self::RESOURCE_NAME;
    }

    public function toArray(Request $request): array
    {
        return [
            'friends' => $this->resource['friends']->map(fn (User $friend): array => [
                'uuid' => $friend->uuid,
                'username' => $friend->username,
                'image' => $this->avatar($friend),
                'since' => $this->since($friend),
            ])->values()->all(),
            'incoming' => $this->resource['incoming']->map(fn (FriendRequest $friendRequest): array => [
                'id' => $friendRequest->id,
                'username' => $friendRequest->sender->username,
                'image' => $this->avatar($friendRequest->sender),
                'created_at' => $this->formatTimestamp($friendRequest->created_at),
            ])->values()->all(),
            'outgoing' => $this->resource['outgoing']->map(fn (FriendRequest $friendRequest): array => [
                'id' => $friendRequest->id,
                'identifier' => $friendRequest->identifier,
                'created_at' => $this->formatTimestamp($friendRequest->created_at),
            ])->values()->all(),
        ];
    }

    /**
     * When the friendship began, which the friend list query selects as `friends_since`; blank if unknown.
     */
    private function since(User $friend): string
    {
        $since = $friend->getAttribute('friends_since');

        return is_string($since) ? CarbonImmutable::parse($since)->toAtomString() : '';
    }

    private function avatar(User $user): string
    {
        return 'https://gravatar.com/avatar/'.md5(Str::lower($user->email));
    }
}
