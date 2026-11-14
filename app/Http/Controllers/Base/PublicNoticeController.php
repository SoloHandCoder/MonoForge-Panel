<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Resources\Api\Client\NoticeFeedResource;
use Pterodactyl\Models\Announcement;
use Pterodactyl\Models\UserNotice;

/**
 * The active announcements for the sign-in pages, which have no user and so no notices of their own.
 */
class PublicNoticeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Collection<int, UserNotice> $notices */
        $notices = new Collection;

        return NoticeFeedResource::make([
            'announcements' => Announcement::query()->active()->latest('id')->get(),
            'notices' => $notices,
        ])->toResponse($request);
    }
}
