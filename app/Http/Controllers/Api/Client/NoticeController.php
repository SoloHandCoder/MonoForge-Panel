<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Http\Resources\Api\Client\NoticeFeedResource;
use Pterodactyl\Models\Announcement;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Notices', 'Announcements and notices the panel shows the authenticated user.')]
class NoticeController extends ClientApiController
{
    public const array FEED_EXAMPLE = [
        'object' => NoticeFeedResource::RESOURCE_NAME,
        'attributes' => [
            'announcements' => [[
                'id' => 1,
                'title' => 'Scheduled maintenance',
                'body' => 'The panel restarts at 18:00 UTC for about ten minutes.',
                'level' => 'warning',
                'dismissible' => true,
                'starts_at' => '2026-11-15T17:00:00+00:00',
                'ends_at' => '2026-11-15T18:30:00+00:00',
                'updated_at' => '2026-11-15T12:00:00+00:00',
            ]],
            'notices' => [[
                'id' => 1,
                'level' => 'danger',
                'title' => 'Low balance',
                'body' => 'Your balance runs out in two days.',
                'action_label' => 'Top up',
                'action_url' => '/account/billing',
                'kind' => 'billing.low_balance',
                'created_at' => '2026-11-15T12:00:00+00:00',
            ]],
        ],
    ];

    /**
     * The active announcements and the user's unread notices.
     */
    #[Endpoint('List notices', 'Returns the active announcements and the unread notices of the authenticated user.')]
    #[ScribeResponse(self::FEED_EXAMPLE, description: 'Notices returned.')]
    #[ResponseField('attributes.announcements[].starts_at', 'string', 'When the announcement starts showing; null shows it from now.', nullable: true)]
    #[ResponseField('attributes.announcements[].ends_at', 'string', 'When the announcement stops showing; null keeps it until removed.', nullable: true)]
    #[ResponseField('attributes.notices[].action_label', 'string', 'The label of the notice button, or null without one.', nullable: true)]
    #[ResponseField('attributes.notices[].action_url', 'string', 'Where the notice button leads, or null without one.', nullable: true)]
    #[ResponseField('attributes.notices[].kind', 'string', 'What raised the notice (one unread notice per kind), or null.', nullable: true)]
    public function index(ClientApiRequest $request): NoticeFeedResource
    {
        return NoticeFeedResource::make([
            'announcements' => Announcement::showing(),
            'notices' => $request->user()->notices()->unread()->latest('id')->get(),
        ]);
    }

    /**
     * Mark one of the user's notices read, which hides it.
     */
    #[Endpoint('Mark notice read', "Marks one of the authenticated user's notices read so the panel stops showing it.")]
    #[UrlParam('notice', 'integer', 'The ID of the notice.', example: 1)]
    #[ScribeResponse(status: 204, description: 'Notice marked read.')]
    public function read(ClientApiRequest $request, int $notice): JsonResponse
    {
        $model = $request->user()->notices()->findOrFail($notice);

        if ($model->read_at === null) {
            $model->forceFill(['read_at' => now()])->save();
        }

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }
}
