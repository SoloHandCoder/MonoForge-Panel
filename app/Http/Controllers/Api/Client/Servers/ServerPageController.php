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
use Pterodactyl\Services\ServerPages\ServerPageText;
use Pterodactyl\Services\ServerPages\ServerPageTheme;
use Pterodactyl\Services\Tunnels\TunnelService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

#[Group('Client API', 'Endpoints authenticated as a panel user using a client API token.')]
#[Subgroup('Server Public Page', 'The shareable public page of a server at /s/{slug}, its look and whether it is listed on the discovery page at /servers. Needs the share.manage permission.')]
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
        'discoverable' => true,
        'hidden_from_discovery' => false,
        'tagline' => 'Islands, events and no pay to win',
        'background_style' => 'gradient',
        'background_from' => '#241a44',
        'background_to' => '#8b5cf6',
        'button_style' => 'rounded',
        'show_players' => true,
        'show_votes' => true,
        'show_address' => true,
        'show_description' => true,
        'discord_url' => 'https://discord.gg/skyblock',
        'website_url' => null,
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
    #[ResponseField('discoverable', 'boolean', 'Whether the owner lists the page on the server discovery page at /servers (on by default).')]
    #[ResponseField('hidden_from_discovery', 'boolean', 'Whether an administrator took the page off the discovery page; the page itself stays published.')]
    #[ResponseField('tagline', 'string', 'One line of plain text under the name, at most 80 characters; empty for none.')]
    #[ResponseField('background_style', 'string', 'solid, gradient (between background_from and background_to) or image (the banner image).', enum: ServerPageTheme::BACKGROUND_STYLES)]
    #[ResponseField('background_from', 'string', "The background colour as #rrggbb, or null for the panel's default.", nullable: true)]
    #[ResponseField('background_to', 'string', 'The end of the gradient as #rrggbb, or null.', nullable: true)]
    #[ResponseField('button_style', 'string', 'The shape of the buttons.', enum: ServerPageTheme::BUTTON_STYLES)]
    #[ResponseField('show_players', 'boolean', 'Whether the page shows the players online.')]
    #[ResponseField('show_votes', 'boolean', 'Whether the page shows the votes and the vote button.')]
    #[ResponseField('show_address', 'boolean', 'Whether the page shows the join address.')]
    #[ResponseField('show_description', 'boolean', 'Whether the page shows the description.')]
    #[ResponseField('discord_url', 'string', 'The Discord invite, or null.', nullable: true)]
    #[ResponseField('website_url', 'string', 'The website, or null.', nullable: true)]
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
    #[BodyParam('banner_color', 'string', 'The accent colour (buttons and the banner without a background) as #rrggbb, or null for the default.', required: true, example: '#8b5cf6', nullable: true)]
    #[BodyParam('discoverable', 'boolean', 'Whether the page is listed on the server discovery page at /servers. On for a new page; left unchanged when omitted.', required: false, example: true)]
    #[BodyParam('tagline', 'string', 'One line of plain text under the name, at most 80 characters, or null for none. Left unchanged when omitted.', required: false, example: 'Islands, events and no pay to win', nullable: true)]
    #[BodyParam('background_style', 'string', 'solid, gradient or image. A gradient needs background_to, and its two colours must leave some text colour readable on both (WCAG AA); image uses the banner image. Left unchanged when omitted.', required: false, example: 'gradient', enum: ServerPageTheme::BACKGROUND_STYLES)]
    #[BodyParam('background_from', 'string', 'The background colour (the start of a gradient) as #rrggbb, or null for the default.', required: false, example: '#241a44', nullable: true)]
    #[BodyParam('background_to', 'string', 'The end of the gradient as #rrggbb, or null.', required: false, example: '#8b5cf6', nullable: true)]
    #[BodyParam('button_style', 'string', 'rounded or square buttons.', required: false, example: 'rounded', enum: ServerPageTheme::BUTTON_STYLES)]
    #[BodyParam('show_players', 'boolean', 'Whether the page shows the players online.', required: false, example: true)]
    #[BodyParam('show_votes', 'boolean', 'Whether the page shows the votes.', required: false, example: true)]
    #[BodyParam('show_address', 'boolean', 'Whether the page shows the join address.', required: false, example: true)]
    #[BodyParam('show_description', 'boolean', 'Whether the page shows the description.', required: false, example: true)]
    #[BodyParam('discord_url', 'string', 'An https Discord invite on discord.gg or discord.com, or null.', required: false, example: 'https://discord.gg/skyblock', nullable: true)]
    #[BodyParam('website_url', 'string', 'An https website address, or null.', required: false, example: 'https://skyblock.example.com', nullable: true)]
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
    #[ResponseField('discoverable', 'boolean', 'Whether the owner lists the page on the server discovery page at /servers (on by default).')]
    #[ResponseField('hidden_from_discovery', 'boolean', 'Whether an administrator took the page off the discovery page; the page itself stays published.')]
    #[ResponseField('tagline', 'string', 'One line of plain text under the name, at most 80 characters; empty for none.')]
    #[ResponseField('background_style', 'string', 'solid, gradient (between background_from and background_to) or image (the banner image).', enum: ServerPageTheme::BACKGROUND_STYLES)]
    #[ResponseField('background_from', 'string', "The background colour as #rrggbb, or null for the panel's default.", nullable: true)]
    #[ResponseField('background_to', 'string', 'The end of the gradient as #rrggbb, or null.', nullable: true)]
    #[ResponseField('button_style', 'string', 'The shape of the buttons.', enum: ServerPageTheme::BUTTON_STYLES)]
    #[ResponseField('show_players', 'boolean', 'Whether the page shows the players online.')]
    #[ResponseField('show_votes', 'boolean', 'Whether the page shows the votes and the vote button.')]
    #[ResponseField('show_address', 'boolean', 'Whether the page shows the join address.')]
    #[ResponseField('show_description', 'boolean', 'Whether the page shows the description.')]
    #[ResponseField('discord_url', 'string', 'The Discord invite, or null.', nullable: true)]
    #[ResponseField('website_url', 'string', 'The website, or null.', nullable: true)]
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

        $this->fillLook($request, $page);

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
    #[ResponseField('discoverable', 'boolean', 'Whether the owner lists the page on the server discovery page at /servers (on by default).')]
    #[ResponseField('hidden_from_discovery', 'boolean', 'Whether an administrator took the page off the discovery page; the page itself stays published.')]
    #[ResponseField('tagline', 'string', 'One line of plain text under the name, at most 80 characters; empty for none.')]
    #[ResponseField('background_style', 'string', 'solid, gradient (between background_from and background_to) or image (the banner image).', enum: ServerPageTheme::BACKGROUND_STYLES)]
    #[ResponseField('background_from', 'string', "The background colour as #rrggbb, or null for the panel's default.", nullable: true)]
    #[ResponseField('background_to', 'string', 'The end of the gradient as #rrggbb, or null.', nullable: true)]
    #[ResponseField('button_style', 'string', 'The shape of the buttons.', enum: ServerPageTheme::BUTTON_STYLES)]
    #[ResponseField('show_players', 'boolean', 'Whether the page shows the players online.')]
    #[ResponseField('show_votes', 'boolean', 'Whether the page shows the votes and the vote button.')]
    #[ResponseField('show_address', 'boolean', 'Whether the page shows the join address.')]
    #[ResponseField('show_description', 'boolean', 'Whether the page shows the description.')]
    #[ResponseField('discord_url', 'string', 'The Discord invite, or null.', nullable: true)]
    #[ResponseField('website_url', 'string', 'The website, or null.', nullable: true)]
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
    #[ResponseField('discoverable', 'boolean', 'Whether the owner lists the page on the server discovery page at /servers (on by default).')]
    #[ResponseField('hidden_from_discovery', 'boolean', 'Whether an administrator took the page off the discovery page; the page itself stays published.')]
    #[ResponseField('tagline', 'string', 'One line of plain text under the name, at most 80 characters; empty for none.')]
    #[ResponseField('background_style', 'string', 'solid, gradient (between background_from and background_to) or image (the banner image).', enum: ServerPageTheme::BACKGROUND_STYLES)]
    #[ResponseField('background_from', 'string', "The background colour as #rrggbb, or null for the panel's default.", nullable: true)]
    #[ResponseField('background_to', 'string', 'The end of the gradient as #rrggbb, or null.', nullable: true)]
    #[ResponseField('button_style', 'string', 'The shape of the buttons.', enum: ServerPageTheme::BUTTON_STYLES)]
    #[ResponseField('show_players', 'boolean', 'Whether the page shows the players online.')]
    #[ResponseField('show_votes', 'boolean', 'Whether the page shows the votes and the vote button.')]
    #[ResponseField('show_address', 'boolean', 'Whether the page shows the join address.')]
    #[ResponseField('show_description', 'boolean', 'Whether the page shows the description.')]
    #[ResponseField('discord_url', 'string', 'The Discord invite, or null.', nullable: true)]
    #[ResponseField('website_url', 'string', 'The website, or null.', nullable: true)]
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

    /** The discovery and look settings; each one left out of the request stays as it is. */
    private function fillLook(UpdateServerPageRequest $request, ServerPage $page): void
    {
        foreach (['discoverable', 'show_players', 'show_votes', 'show_address', 'show_description'] as $flag) {
            if ($request->has($flag)) {
                $page->setAttribute($flag, $request->boolean($flag));
            }
        }

        foreach (['background_style', 'button_style'] as $choice) {
            if ($request->has($choice)) {
                $page->setAttribute($choice, $request->string($choice)->toString());
            }
        }

        foreach (['background_from', 'background_to'] as $color) {
            if ($request->has($color)) {
                $page->setAttribute($color, ServerPageTheme::color($this->text($request, $color)));
            }
        }

        if ($request->has('tagline')) {
            $tagline = ServerPageText::line($this->text($request, 'tagline'), ServerPageText::TAGLINE_MAX);
            $page->tagline = $tagline === '' ? null : $tagline;
        }

        foreach (['discord_url', 'website_url'] as $link) {
            if ($request->has($link)) {
                $value = mb_trim($this->text($request, $link) ?? '');
                $page->setAttribute($link, $value === '' ? null : $value);
            }
        }
    }

    private function text(UpdateServerPageRequest $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : null;
    }

    private function page(Server $server): ?ServerPage
    {
        return ServerPage::query()->where('server_id', $server->id)->first();
    }

    /**
     * @return array{exists: bool, slug: string|null, url: string|null, published: bool, noindex: bool, description: string, banner_color: string|null, banner_image_url: string|null, votes: int, has_address: bool, allow_wake: bool, discoverable: bool, hidden_from_discovery: bool, tagline: string, background_style: string, background_from: string|null, background_to: string|null, button_style: string, show_players: bool, show_votes: bool, show_address: bool, show_description: bool, discord_url: string|null, website_url: string|null}
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
            'discoverable' => $page->discoverable ?? true,
            'hidden_from_discovery' => $page->hidden_from_discovery ?? false,
            'tagline' => $page->tagline ?? '',
            'background_style' => $page->background_style ?? 'solid',
            'background_from' => $page?->background_from,
            'background_to' => $page?->background_to,
            'button_style' => $page->button_style ?? 'rounded',
            'show_players' => $page->show_players ?? true,
            'show_votes' => $page->show_votes ?? true,
            'show_address' => $page->show_address ?? true,
            'show_description' => $page->show_description ?? true,
            'discord_url' => $page?->discord_url,
            'website_url' => $page?->website_url,
        ];
    }
}
