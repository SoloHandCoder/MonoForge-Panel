<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Servers;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Servers\GetServerRequest;
use Pterodactyl\Http\Requests\Api\Admin\Servers\UpdateServerPageDiscoveryRequest;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Share\ServerPage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A server's public page as administrators see it, and the switch that takes it off the server
 * discovery page at /servers without unpublishing it (for a page that breaks the rules, say).
 */
#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Servers', 'Create, update, retrieve, and operate servers.')]
class ServerPageDiscoveryController extends AdminApiController
{
    private const array EXAMPLE = [
        'exists' => true,
        'slug' => 'skyblock',
        'url' => 'https://panel.example.com/s/skyblock',
        'published' => true,
        'discoverable' => true,
        'hidden_from_discovery' => false,
        'listed' => true,
    ];

    private const array NOT_FOUND_ERROR = [
        'errors' => [
            [
                'code' => 'NotFoundHttpException',
                'status' => '404',
                'detail' => 'This server has no public page.',
            ],
        ],
    ];

    #[Endpoint('Get server public page discovery', "Returns whether the server's public page exists, is published, and is listed on the server discovery page.")]
    #[ResponseField('exists', 'boolean', 'Whether the server has a public page.')]
    #[ResponseField('slug', 'string', 'The part of the page address after /s/, or null without a page.', nullable: true)]
    #[ResponseField('url', 'string', 'The full address of the page, or null without a page.', nullable: true)]
    #[ResponseField('published', 'boolean', 'Whether anyone can open the page.')]
    #[ResponseField('discoverable', 'boolean', 'Whether the owner lists the page on the discovery page.')]
    #[ResponseField('hidden_from_discovery', 'boolean', 'Whether an administrator took the page off the discovery page.')]
    #[ResponseField('listed', 'boolean', 'Whether the page is on the discovery page now: published, listed by its owner, not hidden, and its server installed and not suspended.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Public page returned.')]
    public function show(GetServerRequest $request, Server $server): JsonResponse
    {
        return new JsonResponse($this->summary($server, $this->page($server)));
    }

    #[Endpoint('Update server public page discovery', 'Hides the public page from the server discovery page at /servers, or lists it again. The page stays published and reachable at /s/{slug}; the owner cannot undo the hide.')]
    #[BodyParam('hidden', 'boolean', 'Whether the page is kept off the discovery page.', required: true, example: true)]
    #[ResponseField('exists', 'boolean', 'Always true.')]
    #[ResponseField('slug', 'string', 'The part of the page address after /s/.', nullable: true)]
    #[ResponseField('url', 'string', 'The full address of the page.', nullable: true)]
    #[ResponseField('published', 'boolean', 'Whether anyone can open the page.')]
    #[ResponseField('discoverable', 'boolean', 'Whether the owner lists the page on the discovery page.')]
    #[ResponseField('hidden_from_discovery', 'boolean', 'Whether an administrator took the page off the discovery page.')]
    #[ResponseField('listed', 'boolean', 'Whether the page is on the discovery page now.')]
    #[ScribeResponse([...self::EXAMPLE, 'hidden_from_discovery' => true, 'listed' => false], description: 'Discovery setting saved.')]
    #[ScribeResponse(self::NOT_FOUND_ERROR, status: 404, description: 'The server has no public page.')]
    public function update(UpdateServerPageDiscoveryRequest $request, Server $server): JsonResponse
    {
        $page = $this->page($server) ?? throw new NotFoundHttpException('This server has no public page.');
        $hidden = $request->boolean('hidden');

        if ($page->hidden_from_discovery !== $hidden) {
            $page->forceFill(['hidden_from_discovery' => $hidden])->save();

            Activity::event($hidden ? 'admin:server.page-discovery-hide' : 'admin:server.page-discovery-show')
                ->subject($server)
                ->property('name', $server->name)
                ->property('slug', $page->slug)
                ->log();
        }

        return new JsonResponse($this->summary($server, $page));
    }

    private function page(Server $server): ?ServerPage
    {
        return ServerPage::query()->where('server_id', $server->id)->first();
    }

    /**
     * @return array{exists: bool, slug: string|null, url: string|null, published: bool, discoverable: bool, hidden_from_discovery: bool, listed: bool}
     */
    private function summary(Server $server, ?ServerPage $page): array
    {
        $published = $page->published ?? false;
        $discoverable = $page->discoverable ?? false;
        $hidden = $page->hidden_from_discovery ?? false;

        return [
            'exists' => $page instanceof ServerPage,
            'slug' => $page?->slug,
            'url' => $page?->url(),
            'published' => $published,
            'discoverable' => $discoverable,
            'hidden_from_discovery' => $hidden,
            'listed' => $published && $discoverable && ! $hidden && $server->isInstalled() && ! $server->isSuspended(),
        ];
    }
}
