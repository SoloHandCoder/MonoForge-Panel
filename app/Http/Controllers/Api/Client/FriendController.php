<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Http\Requests\Api\Client\Account\Friends\StoreFriendRequestRequest;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Http\Resources\Api\Client\FriendListResource;
use Pterodactyl\Models\FriendRequest;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Friends\FriendService;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Account Friends', 'Friends of the authenticated user, whom they can pick when inviting a subuser.')]
class FriendController extends ClientApiController
{
    public const array LIST_EXAMPLE = [
        'object' => FriendListResource::RESOURCE_NAME,
        'attributes' => [
            'friends' => [[
                'uuid' => '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93',
                'username' => 'sam',
                'image' => 'https://gravatar.com/avatar/9f86d081884c7d659a2feaa0c55ad015',
                'since' => '2026-11-15T12:00:00+00:00',
            ]],
            'incoming' => [[
                'id' => 2,
                'username' => 'jordan',
                'image' => 'https://gravatar.com/avatar/60303ae22b998861bce3b28f33eec1be',
                'created_at' => '2026-11-15T12:00:00+00:00',
            ]],
            'outgoing' => [[
                'id' => 3,
                'identifier' => 'alex@example.com',
                'created_at' => '2026-11-15T12:00:00+00:00',
            ]],
        ],
    ];

    private const array SENT_EXAMPLE = ['message' => FriendService::SENT_MESSAGE];

    private const array TOO_MANY_EXAMPLE = [
        'errors' => [[
            'code' => 'DisplayException',
            'status' => '400',
            'detail' => 'You have too many friend requests waiting. Cancel some before sending more.',
        ]],
    ];

    public function __construct(private readonly FriendService $friends) {}

    /**
     * The user's friends, the requests waiting for their answer and the requests they sent.
     */
    #[Endpoint('List friends', "Returns the authenticated user's friends, the friend requests waiting for their answer, and the requests they sent. A sent request shows only what was typed and stays pending until it is accepted or cancelled.")]
    #[ScribeResponse(self::LIST_EXAMPLE, description: 'Friends and requests returned.')]
    public function index(ClientApiRequest $request): FriendListResource
    {
        $user = $request->user();

        return FriendListResource::make([
            'friends' => $user->friends()->orderBy('username')->get(['users.*', 'friendships.created_at as friends_since']),
            'incoming' => $user->receivedFriendRequests()->open()->with('sender')->latest('id')->get(),
            'outgoing' => $user->sentFriendRequests()->latest('id')->get(),
        ]);
    }

    /**
     * Send a friend request by username or email.
     */
    #[Endpoint('Send friend request', 'Sends a friend request to the user with this username or email. The response is the same whether or not such a user exists. Limited to 5 a minute and 20 an hour, with at most 25 requests waiting.')]
    #[BodyParam('user', 'string', 'The username or email of the user to ask.', required: true, example: 'alex@example.com')]
    #[ScribeResponse(self::SENT_EXAMPLE, status: 202, description: 'Request recorded; the user gets it if they exist.')]
    #[ScribeResponse(self::TOO_MANY_EXAMPLE, status: 400, description: 'Too many requests are waiting.')]
    public function store(StoreFriendRequestRequest $request): JsonResponse
    {
        $this->friends->request($request->user(), $request->string('user')->toString());

        return new JsonResponse(self::SENT_EXAMPLE, JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * Accept a friend request sent to the user.
     */
    #[Endpoint('Accept friend request', 'Accepts a friend request sent to the authenticated user; both users become friends.')]
    #[UrlParam('friendRequest', 'integer', 'The ID of the friend request.', example: 2)]
    #[ScribeResponse(status: 204, description: 'Request accepted.')]
    public function accept(ClientApiRequest $request, int $friendRequest): JsonResponse
    {
        $this->friends->accept($this->incoming($request->user(), $friendRequest));

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Decline a friend request sent to the user.
     */
    #[Endpoint('Decline friend request', 'Declines a friend request sent to the authenticated user. The sender is not told; the request stays pending for them.')]
    #[UrlParam('friendRequest', 'integer', 'The ID of the friend request.', example: 2)]
    #[ScribeResponse(status: 204, description: 'Request declined.')]
    public function decline(ClientApiRequest $request, int $friendRequest): JsonResponse
    {
        $this->friends->decline($this->incoming($request->user(), $friendRequest));

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Withdraw a friend request the user sent.
     */
    #[Endpoint('Cancel friend request', 'Withdraws a friend request the authenticated user sent.')]
    #[UrlParam('friendRequest', 'integer', 'The ID of the friend request.', example: 3)]
    #[ScribeResponse(status: 204, description: 'Request cancelled.')]
    public function cancel(ClientApiRequest $request, int $friendRequest): JsonResponse
    {
        $model = $request->user()->sentFriendRequests()->findOrFail($friendRequest);

        $this->friends->cancel($model);

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Remove a friend.
     */
    #[Endpoint('Remove friend', 'Ends a friendship for both users. Subuser access already given is not changed.')]
    #[UrlParam('friend', 'string', "The friend's user UUID.", example: '6a0c2b9e-3f1d-4c7a-9b8e-2d5f4a1c0e93')]
    #[ScribeResponse(status: 204, description: 'Friend removed.')]
    public function delete(ClientApiRequest $request, string $friend): JsonResponse
    {
        $user = $request->user();
        $model = $user->friends()->where('users.uuid', $friend)->firstOrFail();

        $this->friends->remove($user, $model);

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    private function incoming(User $user, int $id): FriendRequest
    {
        return $user->receivedFriendRequests()->open()->with('sender', 'recipient')->findOrFail($id);
    }
}
