<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\ResponseField;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Help\GetAdminHelpRequest;
use Pterodactyl\Http\Requests\Api\Admin\Settings\GetSettingsRequest;
use Pterodactyl\Http\Requests\Api\Admin\Settings\ResetHelpArticleRequest;
use Pterodactyl\Http\Requests\Api\Admin\Settings\UpdateHelpArticleRequest;
use Pterodactyl\Services\Help\HelpArticles;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The help center's articles: the shipped markdown in resources/help with the administrator's
 * changes, and articles the administrator added. The guides for running the panel (audience
 * admin) are read under Admin > Help; like the rest of the admin API, only root administrators
 * reach these endpoints.
 *
 * @phpstan-import-type HelpEntry from HelpArticles
 */
#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Settings', 'View and update global panel settings.')]
class HelpController extends AdminApiController
{
    private const array ARTICLE_EXAMPLE = [
        'slug' => 'joining-your-server',
        'title' => 'Joining your server',
        'category' => 'Getting started',
        'summary' => 'Find your server address and invite friends to play.',
        'content' => "# Joining your server\n\nEvery server gets a **playit.gg** address.",
        'order' => 20,
        'audience' => 'public',
        'enabled' => true,
        'shipped' => true,
        'overridden' => false,
        'public_url' => '/help/joining-your-server',
    ];

    private const array GUIDE_EXAMPLE = [
        'slug' => 'operating-monoforge',
        'title' => 'Operating MonoForge',
        'category' => 'Administration',
        'summary' => 'Updates, the worker and tuning scripts, and backups.',
        'content' => "# Operating MonoForge\n\nUpdates come from GitHub releases.",
    ];

    public function __construct(private readonly HelpArticles $articles) {}

    #[Endpoint('List help articles', "Returns every help center article with the administrator's changes, turned off ones included, in help center order, the guides for administrators included.")]
    #[ResponseField('data', 'object[]', 'The articles in help center order.')]
    #[ResponseField('data[].audience', 'string', 'Who reads the article: public (the help center) or admin (Admin > Help, root administrators only).', enum: ['public', 'admin'])]
    #[ResponseField('data[].enabled', 'boolean', 'Whether the article is shown.')]
    #[ResponseField('data[].shipped', 'boolean', 'Whether the article ships with the panel. Resetting it brings back the starter text; resetting an added article deletes it.')]
    #[ResponseField('data[].overridden', 'boolean', 'Whether the administrator changed the article (always true for an added one).')]
    #[ResponseField('data[].public_url', 'string', 'Where players read the article.')]
    #[ScribeResponse(['data' => [self::ARTICLE_EXAMPLE]], description: 'Help articles returned.')]
    public function index(GetSettingsRequest $request): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map($this->article(...), $this->articles->all()),
        ]);
    }

    #[Endpoint('Update help article', 'Stores a help article. For a shipped article a blank title, category, summary or content (or one equal to the starter text) keeps following the starter text. Any other slug adds an article for players, which needs a title and content.')]
    #[UrlParam('article', 'string', 'The slug of the help article, the part of its address after /help/.', example: 'joining-your-server')]
    #[BodyParam('title', 'string', 'The title. Required for an added article.', required: false, example: 'Joining your server', nullable: true)]
    #[BodyParam('category', 'string', 'The category the article is listed under.', required: false, example: 'Getting started', nullable: true)]
    #[BodyParam('summary', 'string', 'One sentence about the article, shown in the list and as its search description.', required: false, example: 'Find your server address and invite friends to play.', nullable: true)]
    #[BodyParam('content', 'string', 'The article as markdown. Required for an added article.', required: false, example: "# Joining your server\n\nEvery server gets a **playit.gg** address.", nullable: true)]
    #[BodyParam('order', 'integer', 'Where the article goes: lower comes first. Blank keeps the shipped place, or puts an added article last.', required: false, example: 20, nullable: true)]
    #[BodyParam('enabled', 'boolean', 'Whether the article is shown.', required: true, example: true)]
    #[ScribeResponse(self::ARTICLE_EXAMPLE, description: 'Help article updated.')]
    public function update(UpdateHelpArticleRequest $request, string $article): JsonResponse
    {
        throw_unless(HelpArticles::isSlug($article), NotFoundHttpException::class);

        $adding = ! isset(HelpArticles::defaults()[$article]) && $this->articles->entry($article) === null;
        throw_if(
            $adding && $this->articles->customCount() >= HelpArticles::MAX_CUSTOM,
            ValidationException::withMessages(['title' => 'The help center already has '.HelpArticles::MAX_CUSTOM.' added articles. Delete one first.']),
        );

        $order = $request->input('order');

        $saved = $this->articles->save($article, [
            'title' => $request->string('title')->toString(),
            'category' => $request->string('category')->toString(),
            'summary' => $request->string('summary')->toString(),
            'content' => $request->string('content')->toString(),
            'order' => is_numeric($order) ? (int) $order : (HelpArticles::defaults()[$article]['order'] ?? HelpArticles::DEFAULT_ORDER),
            'enabled' => $request->boolean('enabled'),
        ]);

        return new JsonResponse($this->article($saved));
    }

    #[Endpoint('Reset help article', "Drops the administrator's changes to a shipped help article, which brings back the starter text and shows it again, or deletes an added article.")]
    #[UrlParam('article', 'string', 'The slug of the help article, the part of its address after /help/.', example: 'joining-your-server')]
    #[ScribeResponse(status: 204, description: 'Help article reset.')]
    public function destroy(ResetHelpArticleRequest $request, string $article): Response
    {
        throw_unless(HelpArticles::isSlug($article), NotFoundHttpException::class);
        throw_if($this->articles->entry($article) === null, NotFoundHttpException::class);

        $this->articles->reset($article);

        return $this->returnNoContent();
    }

    #[Endpoint('List admin guides', 'Returns the help articles for administrators (how to run the panel and set up the shop) that are turned on, shown under Admin > Help. Root administrators only.')]
    #[ResponseField('data', 'object[]', 'The guides in help center order, each with its markdown.')]
    #[ScribeResponse(['data' => [self::GUIDE_EXAMPLE]], description: 'Admin guides returned.')]
    public function guides(GetAdminHelpRequest $request): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(fn (array $entry): array => [
                'slug' => $entry['slug'],
                'title' => $entry['title'],
                'category' => $entry['category'],
                'summary' => $entry['summary'],
                'content' => $entry['content'],
            ], $this->articles->published('admin')),
        ]);
    }

    /**
     * @param  HelpEntry  $entry
     * @return array{slug: string, title: string, category: string, summary: string, content: string, order: int, audience: string, enabled: bool, shipped: bool, overridden: bool, public_url: string}
     */
    private function article(array $entry): array
    {
        return [
            'slug' => $entry['slug'],
            'title' => $entry['title'],
            'category' => $entry['category'],
            'summary' => $entry['summary'],
            'content' => $entry['content'],
            'order' => $entry['order'],
            'audience' => $entry['audience'],
            'enabled' => $entry['enabled'],
            'shipped' => $entry['shipped'],
            'overridden' => $entry['overridden'],
            'public_url' => $entry['audience'] === 'admin' ? '/panel/help' : '/help/'.$entry['slug'],
        ];
    }
}
