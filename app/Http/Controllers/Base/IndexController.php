<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Pterodactyl\Http\Controllers\Controller;

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

        return view('templates/base.core');
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
