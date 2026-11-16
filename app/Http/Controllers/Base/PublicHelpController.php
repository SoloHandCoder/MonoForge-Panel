<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\Unauthenticated;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Help\HelpArticles;

/**
 * The help center's articles for players: readable without signing in, in help center order and
 * with their markdown, so the /help page can search titles and text in the browser. Not cached,
 * so an administrator's change shows at once.
 */
#[Group('Public', 'Endpoints that need no account, used by the public homepage.')]
#[Subgroup('Help', 'The help center articles.')]
class PublicHelpController extends Controller
{
    private const array EXAMPLE = [
        'articles' => [[
            'slug' => 'joining-your-server',
            'title' => 'Joining your server',
            'category' => 'Getting started',
            'summary' => 'Find your server address and invite friends to play.',
            'content' => "# Joining your server\n\nEvery server gets a **playit.gg** address.",
        ]],
    ];

    #[Unauthenticated]
    #[Endpoint('List public help articles', 'Returns the help center articles for players that are turned on, in help center order, each with its markdown. Articles for administrators are never listed. Needs no account.')]
    #[ResponseField('articles', 'object[]', 'The articles in help center order: by category, then by their place in it.')]
    #[ResponseField('articles[].slug', 'string', 'The address of the article, after /help/.')]
    #[ResponseField('articles[].category', 'string', 'The category the article is listed under.')]
    #[ResponseField('articles[].summary', 'string', 'One sentence about the article, also its search description. May be empty.')]
    #[ResponseField('articles[].content', 'string', 'The article as markdown.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Articles returned.')]
    public function __invoke(HelpArticles $articles): JsonResponse
    {
        return new JsonResponse([
            'articles' => array_map(fn (array $entry): array => [
                'slug' => $entry['slug'],
                'title' => $entry['title'],
                'category' => $entry['category'],
                'summary' => $entry['summary'],
                'content' => $entry['content'],
            ], $articles->published()),
        ]);
    }
}
