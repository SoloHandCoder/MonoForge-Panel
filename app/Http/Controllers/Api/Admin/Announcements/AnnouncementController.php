<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Announcements;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseFromResource;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Announcements\DeleteAnnouncementRequest;
use Pterodactyl\Http\Requests\Api\Admin\Announcements\GetAnnouncementsRequest;
use Pterodactyl\Http\Requests\Api\Admin\Announcements\StoreAnnouncementRequest;
use Pterodactyl\Http\Requests\Api\Admin\Announcements\UpdateAnnouncementRequest;
use Pterodactyl\Http\Resources\Api\Admin\AnnouncementResource;
use Pterodactyl\Http\Resources\Api\ApiResourceCollection;
use Pterodactyl\Models\Announcement;

#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Announcements', 'Create, update, list, and delete the announcements every user sees above the panel.')]
class AnnouncementController extends AdminApiController
{
    /**
     * List announcements.
     */
    #[Endpoint('List announcements', 'Returns a paginated list of announcements, newest first, with whether each is active, scheduled or ended.')]
    #[QueryParam('page', 'integer', 'Page number to return.', required: false, example: 1)]
    #[QueryParam('per_page', 'integer', 'Results to return per page.', required: false, example: 50)]
    #[ResponseFromResource(AnnouncementResource::class, Announcement::class, description: 'Announcements returned.', collection: true, resourceKey: 'announcement', paginate: 50)]
    public function index(GetAnnouncementsRequest $request): ApiResourceCollection
    {
        $announcements = Announcement::query()->latest('id')->paginate($request->perPage());

        return AnnouncementResource::collection($announcements);
    }

    /**
     * Show announcement.
     */
    #[Endpoint('Get announcement', 'Returns a single announcement by ID.')]
    #[ResponseFromResource(AnnouncementResource::class, Announcement::class, description: 'Announcement returned.', resourceKey: 'announcement')]
    public function show(GetAnnouncementsRequest $request, Announcement $announcement): AnnouncementResource
    {
        return AnnouncementResource::make($announcement);
    }

    /**
     * Create announcement.
     */
    #[Endpoint('Create announcement', 'Creates an announcement shown above the panel to every user, and on the sign-in pages, while its schedule is active.')]
    #[ResponseFromResource(AnnouncementResource::class, Announcement::class, status: 201, description: 'Announcement created.', resourceKey: 'announcement')]
    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $announcement = Announcement::query()->create($request->announcementAttributes());

        Activity::event('admin:announcement.create')
            ->property('title', $announcement->title)
            ->log();

        return AnnouncementResource::make($announcement)->respond(Response::HTTP_CREATED);
    }

    /**
     * Update announcement.
     */
    #[Endpoint('Update announcement', 'Updates an announcement. Users who dismissed it see it again.')]
    #[ResponseFromResource(AnnouncementResource::class, Announcement::class, description: 'Announcement updated.', resourceKey: 'announcement')]
    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): AnnouncementResource
    {
        $announcement->fill($request->announcementAttributes());
        // A dismissal is remembered per update, so an edited announcement shows again.
        $announcement->touch();

        Activity::event('admin:announcement.update')
            ->property('title', $announcement->title)
            ->log();

        return AnnouncementResource::make($announcement);
    }

    /**
     * Delete announcement.
     */
    #[Endpoint('Delete announcement', 'Deletes an announcement.')]
    #[ScribeResponse(status: 204, description: 'Announcement deleted.')]
    public function destroy(DeleteAnnouncementRequest $request, Announcement $announcement): Response
    {
        $announcement->delete();

        Activity::event('admin:announcement.delete')
            ->property('title', $announcement->title)
            ->log();

        return $this->returnNoContent();
    }
}
