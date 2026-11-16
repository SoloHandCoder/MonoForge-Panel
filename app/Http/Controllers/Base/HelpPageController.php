<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Help\HelpArticles;

/**
 * The public help center at /help and its articles at /help/{article}. The SPA renders them from
 * the public help API; the server adds the title, description and canonical link of each page so
 * search engines can index them. An article that does not exist, is turned off or is meant for
 * administrators is a plain 404 shell.
 */
class HelpPageController extends Controller
{
    public const string INDEX_TITLE = 'Help center | MonoForge';

    public const string INDEX_DESCRIPTION = 'Guides for MonoForge players: creating an account, joining your server, tokens and the wallet, renewals, the console, the file editor, plugins, backups, the AI assistant and more.';

    public function index(): Response
    {
        return response()->view('templates/base.core', ['seo' => $this->seo(self::INDEX_TITLE, self::INDEX_DESCRIPTION, '/help')]);
    }

    public function show(HelpArticles $articles, string $article): Response
    {
        $entry = $articles->find($article);
        if ($entry === null) {
            return response()->view('templates/base.core', status: Response::HTTP_NOT_FOUND);
        }

        $description = $entry['summary'] !== '' ? $entry['summary'] : $this->excerpt($entry['content']);

        return response()->view('templates/base.core', ['seo' => $this->seo(
            $entry['title'].' | MonoForge Help',
            $description !== '' ? Str::limit($description, 200) : self::INDEX_DESCRIPTION,
            '/help/'.$entry['slug'],
        )]);
    }

    /**
     * @return array{title: string, description: string, url: string, image: string}
     */
    private function seo(string $title, string $description, string $path): array
    {
        // From the configured address, never the request's Host header, which a client chooses.
        $root = mb_rtrim(config()->string('app.url'), '/');

        return [
            'title' => $title,
            'description' => $description,
            'url' => $root.$path,
            'image' => $root.'/favicons/android-chrome-512x512.png',
        ];
    }

    /** The first paragraph of the markdown as plain text, for an article without a summary. */
    private function excerpt(string $markdown): string
    {
        foreach (preg_split('/\n\s*\n/', str_replace("\r\n", "\n", $markdown)) ?: [] as $block) {
            $block = mb_trim($block);
            if ($block === '' || str_starts_with($block, '#')) {
                continue;
            }

            $text = preg_replace(['/\[([^\]]*)\]\([^)]*\)/', '/[*_`#>~|]+/', '/^\s*(?:[-+]|\d+[.)])\s+/m', '/\s+/'], ['$1', '', '', ' '], strip_tags($block));

            return mb_trim($text ?? '');
        }

        return '';
    }
}
