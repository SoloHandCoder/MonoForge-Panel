<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Http\Response;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Seo\SeoFiles;

/**
 * /sitemap.xml: the homepage, the status page, the help center, the legal pages and every
 * published server page that may be indexed. Public, stateless, rebuilt at most once an hour.
 */
class SitemapController extends Controller
{
    public function __invoke(SeoFiles $files): Response
    {
        return new Response($files->sitemap(), Response::HTTP_OK, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
