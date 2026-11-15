<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Support\StoreCannedReplyRequest;
use Pterodactyl\Http\Requests\Api\Admin\Support\SupportReadRequest;
use Pterodactyl\Http\Requests\Api\Admin\Support\SupportReplyRequest;
use Pterodactyl\Models\Support\SupportCannedReply;
use Pterodactyl\Services\Support\SupportPresenter;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Support', 'The support ticket queue: tickets, replies, internal notes, assignment, canned replies and categories.')]
class CannedReplyController extends AdminApiController
{
    #[Endpoint('List canned replies', 'Returns the saved answers staff can insert into a reply, by title.')]
    #[ScribeResponse(['data' => [SupportPresenter::CANNED_REPLY_EXAMPLE]], description: 'Canned replies returned.')]
    #[ResponseField('data', 'object[]', 'The canned replies.', schema: ['type' => 'array', 'items' => SupportPresenter::CANNED_REPLY_SCHEMA])]
    public function index(SupportReadRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => SupportCannedReply::query()->orderBy('title')->limit(500)->get()
            ->map(fn (SupportCannedReply $reply): array => SupportPresenter::cannedReply($reply))->values()->all()]);
    }

    #[Endpoint('Create a canned reply', 'Saves an answer staff can insert into a reply.')]
    #[ScribeResponse(['data' => SupportPresenter::CANNED_REPLY_EXAMPLE], status: 201, description: 'Canned reply created.')]
    #[ResponseField('data', 'object', 'The canned reply.', schema: SupportPresenter::CANNED_REPLY_SCHEMA)]
    public function store(StoreCannedReplyRequest $request): JsonResponse
    {
        $reply = SupportCannedReply::query()->create($request->replyAttributes());

        Activity::event('admin:support.canned-reply.create')->property(['title' => $reply->title])->log();

        return new JsonResponse(['data' => SupportPresenter::cannedReply($reply)], Response::HTTP_CREATED);
    }

    #[Endpoint('Update a canned reply', 'Changes the title and text of a canned reply.')]
    #[UrlParam('canned_reply', 'integer', 'The canned reply ID.', example: 1)]
    #[ScribeResponse(['data' => SupportPresenter::CANNED_REPLY_EXAMPLE], description: 'Canned reply updated.')]
    #[ResponseField('data', 'object', 'The canned reply.', schema: SupportPresenter::CANNED_REPLY_SCHEMA)]
    public function update(StoreCannedReplyRequest $request, int $cannedReply): JsonResponse
    {
        $reply = SupportCannedReply::query()->findOrFail($cannedReply);
        $reply->fill($request->replyAttributes())->save();

        Activity::event('admin:support.canned-reply.update')->property(['title' => $reply->title])->log();

        return new JsonResponse(['data' => SupportPresenter::cannedReply($reply)]);
    }

    #[Endpoint('Delete a canned reply', 'Deletes a canned reply.')]
    #[UrlParam('canned_reply', 'integer', 'The canned reply ID.', example: 1)]
    #[ScribeResponse(status: 204, description: 'Canned reply deleted.')]
    public function destroy(SupportReplyRequest $request, int $cannedReply): Response
    {
        $reply = SupportCannedReply::query()->findOrFail($cannedReply);
        $reply->delete();

        Activity::event('admin:support.canned-reply.delete')->property(['title' => $reply->title])->log();

        return $this->returnNoContent();
    }
}
