<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Http\Response;
use Pterodactyl\Http\Controllers\Controller;

/**
 * The public server discovery page at /servers. The SPA renders the list from the public API; the
 * server adds the title, description and canonical link so search engines can index it.
 */
class ServerDiscoveryPageController extends Controller
{
    public const string TITLE = 'Discover game servers | MonoForge';

    public const string DESCRIPTION = 'Find Minecraft, Terraria and other game servers hosted on MonoForge: see who is online, how many players are on, and copy the address to join.';

    public function __invoke(): Response
    {
        // From the configured address, never the request's Host header, which a client chooses.
        $root = mb_rtrim(config()->string('app.url'), '/');

        return response()->view('templates/base.core', ['seo' => [
            'title' => self::TITLE,
            'description' => self::DESCRIPTION,
            'url' => $root.'/servers',
            'image' => $root.'/favicons/android-chrome-512x512.png',
        ]]);
    }
}
