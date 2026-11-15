<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Http\Response;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Services\ServerPages\PublicServerPages;
use Pterodactyl\Services\ServerPages\ServerPageBanners;

class ServerPageBannerController extends Controller
{
    /**
     * Serves the banner of a published server page. Stateless; the names are random and never
     * reused, but a page can be unpublished, so it is cached for a day rather than for good. The
     * type is the one fixed at upload.
     */
    public function __invoke(PublicServerPages $pages, ServerPageBanners $banners, string $slug, string $file): Response
    {
        $page = $pages->find($slug);
        abort_if(! $page instanceof ServerPage || $page->banner_image !== $file, Response::HTTP_NOT_FOUND);

        $stored = $banners->read($file);
        abort_if($stored === null, Response::HTTP_NOT_FOUND);

        return new Response($stored['contents'], Response::HTTP_OK, [
            'Content-Type' => $stored['mime'],
            'Content-Disposition' => 'inline; filename="'.$file.'"',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
