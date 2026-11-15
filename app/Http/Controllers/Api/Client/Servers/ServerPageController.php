<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Page\ServerPageRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Page\UpdateServerPageRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Page\UploadServerPageBannerRequest;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Models\Share\ServerPageSlugHold;
use Pterodactyl\Services\ServerPages\PublicServerPages;
use Pterodactyl\Services\ServerPages\ServerPageBanners;
use Pterodactyl\Services\Tunnels\TunnelService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Server Public Page', 'The shareable public page of a server at /s/{slug}. Needs the share.manage permission.')]
class ServerPageController extends ClientApiController
{
    private const array EXAMPLE = [
        'exists' => true,
        'slug' => 'skyblock',
        'url' => 'https://panel.example.com/s/skyblock',
        'published' => true,
        'noindex' => false,
        'description' => "A friendly **skyblock** server.\n\n- Weekly events\n- No pay to win",
        'banner_color' => '#8b5cf6',
        'banner_image_url' => '/s/skyblock/banner/3f786850e387550fdab836ed7e6dc881de23001b.png',
        'votes' => 42,
        'has_address' => true,
        'allow_wake' => false,
    ];

    private const array CONFLICT_ERROR = [
        'errors' => [
            [
                'code' => 'ConflictHttpException',
                'status' => '409',
                'detail' => 'Save the public page before adding a banner image.',
            ],
        ],
    ];

    public function __construct(
        private readonly PublicServerPages $pages,
        private readonly ServerPageBanners $banners,
        private readonly TunnelService $tunnels,
    ) {}

    #[Endpoint('Get server public page', "Returns the settings of the server's public page. A server without one returns exists false and the defaults.")]
    #[ResponseField('exists', 'boolean', 'Whether the page has been saved before.')]
    #[ResponseField('slug', 'string', 'The part of the address after /s/, or null before the page is saved.', nullable: true)]
    #[ResponseField('url', 'string', 'The full address of the page, or null before the page is saved.', nullable: true)]
    #[ResponseField('published', 'boolean', 'Whether anyone can open the page.')]
    #[ResponseField('noindex', 'boolean', 'Whether search engines are asked not to list the page.')]
    #[ResponseField('description', 'string', 'The description in markdown; links, bold, italics, lists and headings are shown, HTML is shown as text.')]
    #[ResponseField('banner_color', 'string', "The banner colour as #rrggbb, or null for the panel's default.", nullable: true)]
    #[ResponseField('banner_image_url', 'string', 'The path of the banner image, or null when there is none.', nullable: true)]
    #[ResponseField('votes', 'integer', 'The total votes the page has received.')]
    #[ResponseField('has_address', 'boolean', 'Whether the page shows a join address: only a live playit tunnel address is ever shown.')]
    #[ResponseField('allow_wake', 'boolean', 'Whether visitors may wake the sleeping server from the page.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Public page returned.')]
    public function show(ServerPageRequest $request, Server $server): JsonResponse
    {
        return new JsonResponse($this->settings($server, $this->page($server)));
    }

    #[Endpoint('Update server public page', "Creates or updates the server's public page. The slug is 3 to 32 lowercase letters, numbers and single hyphens, unique, and not a reserved word. Publishing makes the page reachable at /s/{slug}; unpublishing hides it again.")]
    #[BodyParam('slug', 'string', 'The part of the address after /s/.', required: true, example: 'skyblock')]
    #[BodyParam('published', 'boolean', 'Whether anyone can open the page.', required: true, example: true)]
    #[BodyParam('noindex', 'boolean', 'Whether search engines are asked not to list the page.', required: true, example: false)]
    #[BodyParam('description', 'string', 'The description in markdown, at most 2000 characters, or null for none.', required: true, example: 'A friendly **skyblock** server.', nullable: true)]
    #[BodyParam('banner_color', 'string', 'The banner colour as #rrggbb, or null for the default.', required: true, example: '#8b5cf6', nullable: true)]
    #[BodyParam('allow_wake', 'boolean', 'Whether visitors may wake the server from the page while it sleeps. Off by default; left unchanged when omitted.', required: false, example: false)]
    #[ResponseField('exists', 'boolean', 'Always true after a save.')]
    #[ResponseField('slug', 'string', 'The part of the address after /s/.', nullable: true)]
    #[ResponseField('url', 'string', 'The full address of the page.', nullable: true)]
    #[ResponseField('published', 'boolean', 'Whether anyone can open the page.')]
    #[ResponseField('noindex', 'boolean', 'Whether search engines are asked not to list the page.')]
    #[ResponseField('description', 'string', 'The description in markdown.')]
    #[ResponseField('banner_color', 'string', 'The banner colour as #rrggbb, or null for the default.', nullable: true)]
    #[ResponseField('banner_image_url', 'string', 'The path of the banner image, or null when there is none.', nullable: true)]
    #[ResponseField('votes', 'integer', 'The total votes the page has received.')]
    #[ResponseField('has_address', 'boolean', 'Whether the page shows a join address.')]
    #[ResponseField('allow_wake', 'boolean', 'Whether visitors may wake the sleeping server from the page.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Public page saved.')]
    public function update(UpdateServerPageRequest $request, Server $server): JsonResponse
    {
        $page = $this->page($server) ?? new ServerPage(['server_id' => $server->id]);
        $wasPublished = $page->exists && $page->published;
        $oldSlug = $page->exists ? $page->slug : null;
        $description = $request->input('description');
        $color = $request->input('banner_color');

        $page->fill([
            'slug' => $request->string('slug')->toString(),
            'published' => $request->boolean('published'),
            'noindex' => $request->boolean('noindex'),
            'description' => is_string($description) && mb_trim($description) !== '' ? $description : null,
            'banner_color' => is_string($color) ? mb_strtolower($color) : null,
        ]);
        if ($request->has('allow_wake')) {
            $page->allow_wake = $request->boolean('allow_wake');
        }

        // Unpublishing gives the banner a new name, so the old address (and any copy a CDN keeps
        // of it) stops working at once.
        if ($wasPublished && ! $page->published && $page->banner_image !== null) {
            $page->banner_image = $this->banners->rename($page->banner_image);
        }

        $page->save();

        if ($oldSlug !== null && $oldSlug !== $page->slug) {
            ServerPageSlugHold::rename($server->id, $oldSlug, $page->slug);
        }

        Activity::event('server:page.update')->property('slug', $page->slug)->log();
        if ($page->published !== $wasPublished) {
            Activity::event($page->published ? 'server:page.publish' : 'server:page.unpublish')->property('slug', $page->slug)->log();
        }

        $this->pages->forget($page);

        return new JsonResponse($this->settings($server, $page));
    }

    #[Endpoint('Upload server public page banner', "Stores a banner image for the public page and deletes the one it replaces. PNG, JPEG or WebP only, at most 1 MB and 4096 pixels a side; the type is decided from the file's content and the image is re-encoded.")]
    #[BodyParam('banner', 'file', 'The banner image.', required: true)]
    #[ResponseField('exists', 'boolean', 'Always true.')]
    #[ResponseField('slug', 'string', 'The part of the address after /s/.', nullable: true)]
    #[ResponseField('url', 'string', 'The full address of the page.', nullable: true)]
    #[ResponseField('published', 'boolean', 'Whether anyone can open the page.')]
    #[ResponseField('noindex', 'boolean', 'Whether search engines are asked not to list the page.')]
    #[ResponseField('description', 'string', 'The description in markdown.')]
    #[ResponseField('banner_color', 'string', 'The banner colour as #rrggbb, or null for the default.', nullable: true)]
    #[ResponseField('banner_image_url', 'string', 'The path of the new banner image.', nullable: true)]
    #[ResponseField('votes', 'integer', 'The total votes the page has received.')]
    #[ResponseField('has_address', 'boolean', 'Whether the page shows a join address.')]
    #[ResponseField('allow_wake', 'boolean', 'Whether visitors may wake the sleeping server from the page.')]
    #[ScribeResponse(self::EXAMPLE, description: 'Banner stored.')]
    #[ScribeResponse(self::CONFLICT_ERROR, status: 409, description: 'The page has not been saved yet.')]
    public function uploadBanner(UploadServerPageBannerRequest $request, Server $server): JsonResponse
    {
        $page = $this->page($server) ?? throw new ConflictHttpException('Save the public page before adding a banner image.');

        $previous = $page->banner_image;
        $page->forceFill(['banner_image' => $this->banners->store($request->upload())])->save();
        $this->banners->delete($previous);

        Activity::event('server:page.banner')->property('slug', $page->slug)->log();
        $this->pages->forget($page);

        return new JsonResponse($this->settings($server, $page));
    }

    #[Endpoint('Delete server public page banner', 'Deletes the banner image of the public page; the banner colour is shown instead.')]
    #[ResponseField('exists', 'boolean', 'Whether the page has been saved before.')]
    #[ResponseField('slug', 'string', 'The part of the address after /s/.', nullable: true)]
    #[ResponseField('url', 'string', 'The full address of the page.', nullable: true)]
    #[ResponseField('published', 'boolean', 'Whether anyone can open the page.')]
    #[ResponseField('noindex', 'boolean', 'Whether search engines are asked not to list the page.')]
    #[ResponseField('description', 'string', 'The description in markdown.')]
    #[ResponseField('banner_color', 'string', 'The banner colour as #rrggbb, or null for the default.', nullable: true)]
    #[ResponseField('banner_image_url', 'string', 'Always null after the delete.', nullable: true)]
    #[ResponseField('votes', 'integer', 'The total votes the page has received.')]
    #[ResponseField('has_address', 'boolean', 'Whether the page shows a join address.')]
    #[ResponseField('allow_wake', 'boolean', 'Whether visitors may wake the sleeping server from the page.')]
    #[ScribeResponse([...self::EXAMPLE, 'banner_image_url' => null], description: 'Banner deleted.')]
    public function deleteBanner(ServerPageRequest $request, Server $server): JsonResponse
    {
        $page = $this->page($server);
        if ($page instanceof ServerPage && $page->banner_image !== null) {
            $this->banners->delete($page->banner_image);
            $page->forceFill(['banner_image' => null])->save();

            Activity::event('server:page.banner-delete')->property('slug', $page->slug)->log();
            $this->pages->forget($page);
        }

        return new JsonResponse($this->settings($server, $page));
    }

    private function page(Server $server): ?ServerPage
    {
        return ServerPage::query()->where('server_id', $server->id)->first();
    }

    /**
     * @return array{exists: bool, slug: string|null, url: string|null, published: bool, noindex: bool, description: string, banner_color: string|null, banner_image_url: string|null, votes: int, has_address: bool, allow_wake: bool}
     */
    private function settings(Server $server, ?ServerPage $page): array
    {
        return [
            'exists' => $page instanceof ServerPage,
            'slug' => $page?->slug,
            'url' => $page?->url(),
            'published' => $page->published ?? false,
            'noindex' => $page->noindex ?? false,
            'description' => $page->description ?? '',
            'banner_color' => $page?->banner_color,
            'banner_image_url' => $page?->bannerUrl(),
            'votes' => $page->votes ?? 0,
            'has_address' => $this->tunnels->displayAddress($server) !== null,
            'allow_wake' => $page->allow_wake ?? false,
        ];
    }
}
