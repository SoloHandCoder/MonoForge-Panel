<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Services\ServerPages\PublicServerPages;
use Pterodactyl\Services\Status\StatusPageSettings;

class IndexController extends Controller
{
    /**
     * The SPA. A visitor who is not signed in gets the public homepage at "/", the only page
     * search engines may index; it carries its title, description and Open Graph tags here so
     * they are there without running the SPA.
     */
    public function index(Request $request): View
    {
        if ($request->user() === null && $request->path() === '/') {
            return view('templates/base.core', ['seo' => $this->homeSeo()]);
        }

        // The public status page may be indexed too, for anyone, while it is turned on.
        if ($request->path() === 'status' && StatusPageSettings::enabled()) {
            $root = mb_rtrim(config()->string('app.url'), '/');

            return view('templates/base.core', ['seo' => [
                'title' => 'Status | MonoForge',
                'description' => 'Live status and uptime of the MonoForge panel and game server nodes.',
                'url' => $root.'/status',
                'image' => $root.'/favicons/android-chrome-512x512.png',
            ]]);
        }

        return view('templates/base.core');
    }

    /**
     * A server's public page at /s/{slug}. It carries its own title, description, banner image and
     * colour as Open Graph tags, so a link pasted into Discord shows a card, and it may be indexed
     * unless the owner turned that off. A page that is not published is a plain 404 shell.
     */
    public function serverPage(PublicServerPages $pages, string $slug): Response
    {
        $page = $pages->find($slug);
        if (! $page instanceof ServerPage || $page->server === null) {
            return response()->view('templates/base.core', status: Response::HTTP_NOT_FOUND);
        }

        $root = mb_rtrim(config()->string('app.url'), '/');
        $name = $page->server->name;
        $summary = $this->plainText($page->description ?? '');
        $banner = $page->bannerUrl();

        return response()->view('templates/base.core', ['seo' => [
            'title' => $name.' | MonoForge',
            'description' => $summary !== '' ? Str::limit($summary, 200) : "Join {$name}, a game server hosted on MonoForge.",
            'url' => $page->url(),
            'image' => $banner !== null ? $root.$banner : $root.'/favicons/android-chrome-512x512.png',
            'robots' => $page->noindex ? 'noindex' : 'index, follow',
            'card' => $banner !== null ? 'summary_large_image' : 'summary',
            'theme_color' => $page->banner_color,
        ]]);
    }

    /** Markdown reduced to one line of plain text for a link preview. */
    private function plainText(string $markdown): string
    {
        $text = preg_replace(['/\[([^\]]*)\]\([^)]*\)/', '/[*_`#>~|]+/', '/^\s*(?:[-+]|\d+[.)])\s+/m', '/\s+/'], ['$1', '', '', ' '], strip_tags($markdown));

        return mb_trim($text ?? '');
    }

    /**
     * @return array{title: string, description: string, url: string, image: string}
     */
    private function homeSeo(): array
    {
        // From the configured address, never the request's Host header, which a client chooses.
        $root = mb_rtrim(config()->string('app.url'), '/');

        return [
            'title' => 'MonoForge | Game server hosting for Minecraft and Terraria',
            'description' => 'Spin up a Minecraft Java or Terraria server in seconds with MonoForge: pay with tokens, play through a ready-made address, and manage everything from the browser with a file editor, backups and Discord support.',
            'url' => $root.'/',
            'image' => $root.'/favicons/android-chrome-512x512.png',
        ];
    }
}
