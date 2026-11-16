<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Knuckles\Scribe\Attributes\Unauthenticated;
use Knuckles\Scribe\Attributes\UrlParam;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Middleware\VerifyCaptcha;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Services\ServerPages\PublicServerPages;
use Pterodactyl\Services\ServerPages\ServerDiscovery;
use Pterodactyl\Services\ServerPages\ServerPageVisitor;
use Pterodactyl\Services\ServerPages\ServerPageVotes;
use Pterodactyl\Services\ServerPages\ServerPageWake;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

/**
 * The shareable public server pages at /s/{slug}: their data, votes and the wake button. No
 * session; a visitor is known only by keyed hashes of their address and visitor cookie.
 */
#[Group('Public', 'Endpoints that need no account, used by the public homepage.')]
#[Subgroup('Server pages', 'The shareable public pages of servers at /s/{slug}, and the server discovery page at /servers.')]
class PublicServerPageController extends Controller
{
    private const array STATUS_SCHEMA = [
        'type' => 'string',
        'enum' => ['online', 'offline', 'starting', 'sleeping', 'unknown'],
        'description' => 'online when the game answers or Wings reports it running, sleeping when auto-sleep stopped it, unknown when its node cannot be reached.',
        'example' => 'online',
    ];

    private const array BANNER_SCHEMA = [
        'type' => 'object',
        'required' => ['color', 'image_url'],
        'properties' => [
            'color' => ['type' => 'string', 'nullable' => true, 'description' => 'The banner colour as #rrggbb, or null for the default.', 'example' => '#8b5cf6'],
            'image_url' => ['type' => 'string', 'nullable' => true, 'description' => 'The path of the banner image, or null for none.', 'example' => '/s/skyblock/banner/3f786850e387550fdab836ed7e6dc881de23001b.png'],
        ],
    ];

    private const array PLAYERS_SCHEMA = [
        'type' => 'object',
        'nullable' => true,
        'description' => 'The players online and the limit, when the game says; null otherwise.',
        'required' => ['online', 'max'],
        'properties' => [
            'online' => ['type' => 'integer', 'example' => 3],
            'max' => ['type' => 'integer', 'example' => 20],
        ],
    ];

    private const array THEME_SCHEMA = [
        'type' => 'object',
        'description' => "The page's look. The text colours are picked by the panel so every combination stays readable (WCAG AA).",
        'required' => ['accent', 'accent_text', 'background', 'text', 'button_style'],
        'properties' => [
            'accent' => ['type' => 'string', 'description' => 'The accent colour as #rrggbb.', 'example' => '#8b5cf6'],
            'accent_text' => ['type' => 'string', 'description' => 'The text colour on the accent: #ffffff or #000000.', 'example' => '#ffffff'],
            'background' => [
                'type' => 'object',
                'required' => ['style', 'from', 'to', 'image_url'],
                'properties' => [
                    'style' => ['type' => 'string', 'enum' => ['solid', 'gradient', 'image'], 'description' => 'A solid colour, a gradient between two colours, or the banner image.', 'example' => 'gradient'],
                    'from' => ['type' => 'string', 'description' => 'The background colour (the start of a gradient) as #rrggbb.', 'example' => '#241a44'],
                    'to' => ['type' => 'string', 'nullable' => true, 'description' => 'The end of a gradient as #rrggbb, or null.', 'example' => '#8b5cf6'],
                    'image_url' => ['type' => 'string', 'nullable' => true, 'description' => 'The banner image path when the style is image, or null.', 'example' => null],
                ],
            ],
            'text' => ['type' => 'string', 'description' => 'The text colour on the background: #ffffff or #000000 (always #ffffff over an image, which gets a dark scrim).', 'example' => '#ffffff'],
            'button_style' => ['type' => 'string', 'enum' => ['rounded', 'square'], 'description' => 'The shape of the buttons.', 'example' => 'rounded'],
        ],
    ];

    private const array SECTIONS_SCHEMA = [
        'type' => 'object',
        'description' => 'Which parts of the page the owner shows. A hidden section is also left out of the data.',
        'required' => ['players', 'votes', 'address', 'description'],
        'properties' => [
            'players' => ['type' => 'boolean', 'example' => true],
            'votes' => ['type' => 'boolean', 'example' => true],
            'address' => ['type' => 'boolean', 'example' => true],
            'description' => ['type' => 'boolean', 'example' => true],
        ],
    ];

    private const array LINKS_SCHEMA = [
        'type' => 'object',
        'description' => 'The social links, each an https address or null. Shown with rel="nofollow ugc noopener noreferrer".',
        'required' => ['discord', 'website'],
        'properties' => [
            'discord' => ['type' => 'string', 'nullable' => true, 'description' => 'A Discord invite on discord.gg or discord.com.', 'example' => 'https://discord.gg/skyblock'],
            'website' => ['type' => 'string', 'nullable' => true, 'description' => 'The server website.', 'example' => 'https://skyblock.example.com'],
        ],
    ];

    private const array GAME_SCHEMA = [
        'type' => 'object',
        'nullable' => true,
        'description' => 'The game in Admin > Games, or null when the panel does not know it.',
        'required' => ['slug', 'name', 'color'],
        'properties' => [
            'slug' => ['type' => 'string', 'example' => 'minecraft'],
            'name' => ['type' => 'string', 'example' => 'Minecraft'],
            'color' => ['type' => 'string', 'description' => 'The game colour as #rrggbb.', 'example' => '#62B47A'],
        ],
    ];

    private const array THEME_EXAMPLE = [
        'accent' => '#8b5cf6',
        'accent_text' => '#ffffff',
        'background' => ['style' => 'gradient', 'from' => '#241a44', 'to' => '#8b5cf6', 'image_url' => null],
        'text' => '#ffffff',
        'button_style' => 'rounded',
    ];

    private const array SECTIONS_EXAMPLE = ['players' => true, 'votes' => true, 'address' => true, 'description' => true];

    private const array DISCOVERY_EXAMPLE = [
        'data' => [[
            'slug' => 'skyblock',
            'url' => '/s/skyblock',
            'name' => 'Skyblock Island',
            'tagline' => 'Islands, events and no pay to win',
            'description' => 'A friendly skyblock server. Weekly events, no pay to win.',
            'game' => ['slug' => 'minecraft', 'name' => 'Minecraft', 'color' => '#62B47A'],
            'status' => 'online',
            'players' => ['online' => 3, 'max' => 20],
            'votes' => 42,
            'address' => 'skyblock.joinmc.link',
            'theme' => self::THEME_EXAMPLE,
            'sections' => self::SECTIONS_EXAMPLE,
            'created_at' => '2026-11-10T18:30:00+00:00',
        ]],
        'meta' => ['page' => 1, 'per_page' => 24, 'has_more' => false],
        'games' => [['slug' => 'minecraft', 'name' => 'Minecraft', 'color' => '#62B47A'], ['slug' => 'terraria', 'name' => 'Terraria', 'color' => '#4C9A2A']],
    ];

    private const array DISCOVERY_ENTRIES_SCHEMA = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['slug', 'url', 'name', 'tagline', 'description', 'game', 'status', 'players', 'votes', 'address', 'theme', 'sections', 'created_at'],
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'The part of the page address after /s/.', 'example' => 'skyblock'],
                'url' => ['type' => 'string', 'description' => 'The path of the public page.', 'example' => '/s/skyblock'],
                'name' => ['type' => 'string', 'description' => 'The server name, at most 64 characters.', 'example' => 'Skyblock Island'],
                'tagline' => ['type' => 'string', 'description' => 'The tagline, plain text up to 80 characters; empty for none.', 'example' => 'Islands, events and no pay to win'],
                'description' => ['type' => 'string', 'description' => 'The start of the description as plain text, up to 160 characters; empty for none or when hidden.', 'example' => 'A friendly skyblock server.'],
                'game' => self::GAME_SCHEMA,
                'status' => self::STATUS_SCHEMA,
                'players' => self::PLAYERS_SCHEMA,
                'votes' => ['type' => 'integer', 'example' => 42],
                'address' => ['type' => 'string', 'nullable' => true, 'description' => 'The playit address to join with, or null.', 'example' => 'skyblock.joinmc.link'],
                'theme' => self::THEME_SCHEMA,
                'sections' => self::SECTIONS_SCHEMA,
                'created_at' => ['type' => 'string', 'nullable' => true, 'description' => 'When the page was made.', 'example' => '2026-11-10T18:30:00+00:00'],
            ],
        ],
    ];

    private const array DISCOVERY_META_SCHEMA = [
        'type' => 'object',
        'required' => ['page', 'per_page', 'has_more'],
        'properties' => [
            'page' => ['type' => 'integer', 'example' => 1],
            'per_page' => ['type' => 'integer', 'example' => 24],
            'has_more' => ['type' => 'boolean', 'description' => 'Whether another page follows.', 'example' => false],
        ],
    ];

    private const array GAMES_SCHEMA = [
        'type' => 'array',
        'description' => 'The games of Admin > Games, to filter by.',
        'items' => [
            'type' => 'object',
            'required' => ['slug', 'name', 'color'],
            'properties' => [
                'slug' => ['type' => 'string', 'example' => 'minecraft'],
                'name' => ['type' => 'string', 'example' => 'Minecraft'],
                'color' => ['type' => 'string', 'example' => '#62B47A'],
            ],
        ],
    ];

    private const array VALIDATION_ERROR = [
        'errors' => [
            [
                'code' => 'ValidationException',
                'status' => '422',
                'detail' => 'The selected sort is invalid.',
                'meta' => ['source_field' => 'sort', 'rule' => 'in'],
            ],
        ],
    ];

    private const array PAGE_EXAMPLE = [
        'slug' => 'skyblock',
        'name' => 'Skyblock Island',
        'tagline' => 'Islands, events and no pay to win',
        'description' => "A friendly **skyblock** server.\n\n- Weekly events\n- No pay to win",
        'banner' => ['color' => '#8b5cf6', 'image_url' => '/s/skyblock/banner/3f786850e387550fdab836ed7e6dc881de23001b.png'],
        'game' => 'Minecraft',
        'software' => 'Paper',
        'version' => '1.21.4',
        'status' => 'online',
        'players' => ['online' => 3, 'max' => 20],
        'address' => 'skyblock.joinmc.link',
        'join_url' => null,
        'votes' => 42,
        'can_wake' => false,
        'theme' => self::THEME_EXAMPLE,
        'sections' => self::SECTIONS_EXAMPLE,
        'links' => ['discord' => 'https://discord.gg/skyblock', 'website' => null],
    ];

    private const array NOT_FOUND_ERROR = [
        'errors' => [
            [
                'code' => 'NotFoundHttpException',
                'status' => '404',
                'detail' => 'The requested resource could not be found on the server.',
            ],
        ],
    ];

    private const array TOO_MANY_ERROR = [
        'errors' => [
            [
                'code' => 'TooManyRequestsHttpException',
                'status' => '429',
                'detail' => 'This server was woken a moment ago. Please try again shortly.',
            ],
        ],
    ];

    private const array UNSUPPORTED_ERROR = [
        'errors' => [
            [
                'code' => 'UnsupportedMediaTypeHttpException',
                'status' => '415',
                'detail' => self::JSON_MESSAGE,
            ],
        ],
    ];

    private const array CROSS_SITE_ERROR = [
        'errors' => [
            [
                'code' => 'AccessDeniedHttpException',
                'status' => '403',
                'detail' => self::ORIGIN_MESSAGE,
            ],
        ],
    ];

    private const array WAKE_OFF_ERROR = [
        'errors' => [
            [
                'code' => 'AccessDeniedHttpException',
                'status' => '403',
                'detail' => self::WAKE_OFF_MESSAGE,
            ],
        ],
    ];

    private const string JSON_MESSAGE = 'Send this request with a JSON body (Content-Type: application/json) and the X-Requested-With: XMLHttpRequest header.';

    private const string ORIGIN_MESSAGE = 'This request must come from the panel itself.';

    private const string WAKE_OFF_MESSAGE = 'The owner of this server has not allowed visitors to wake it.';

    public function __construct(private readonly PublicServerPages $pages) {}

    #[Unauthenticated]
    #[Endpoint('List discoverable server pages', 'Returns the server discovery page: the published pages whose owner lists them and an administrator has not hidden, of servers that are installed and not suspended, 24 a page. The status and players are the ones each page last showed (refreshed every few minutes, never asked of the game per request), and the only address is the playit tunnel address. Cached for 30 seconds.')]
    #[QueryParam('game', 'string', 'Only servers of this game, by its slug in Admin > Games.', required: false, example: 'minecraft')]
    #[QueryParam('search', 'string', 'Only servers whose name contains this, at most 64 characters.', required: false, example: 'sky')]
    #[QueryParam('sort', 'string', 'players (most players online first, the default), votes (most votes first) or newest.', required: false, example: 'players', enum: ServerDiscovery::SORTS)]
    #[QueryParam('page', 'integer', 'The page of results, from 1.', required: false, example: 1)]
    #[QueryParam('per_page', 'integer', 'Results per page, 1 to 24 (the default).', required: false, example: 24)]
    #[ResponseField('data', 'object[]', 'The listed server pages.', schema: self::DISCOVERY_ENTRIES_SCHEMA)]
    #[ResponseField('meta', 'object', 'The page of results.', schema: self::DISCOVERY_META_SCHEMA)]
    #[ResponseField('games', 'object[]', 'The games to filter by.', schema: self::GAMES_SCHEMA)]
    #[ScribeResponse(self::DISCOVERY_EXAMPLE, description: 'Server pages listed.')]
    #[ScribeResponse(self::VALIDATION_ERROR, status: 422, description: 'A filter is not valid.')]
    public function index(Request $request, ServerDiscovery $discovery): JsonResponse
    {
        $request->validate([
            'game' => ['sometimes', 'nullable', 'string', 'regex:/\A[a-z0-9][a-z0-9-]{0,63}\z/'],
            'search' => ['sometimes', 'nullable', 'string', 'max:'.ServerDiscovery::SEARCH_MAX],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(ServerDiscovery::SORTS)],
            'page' => ['sometimes', 'nullable', 'integer', 'between:1,'.ServerDiscovery::MAX_PAGE],
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.ServerDiscovery::PER_PAGE],
        ]);

        $game = $request->string('game')->toString();
        $sort = $request->string('sort')->toString();

        return new JsonResponse($discovery->list(
            $game === '' ? null : $game,
            $request->string('search')->toString(),
            $sort === '' ? 'players' : $sort,
            $request->integer('page', 1),
            $request->integer('per_page', ServerDiscovery::PER_PAGE),
        ), headers: ['Cache-Control' => 'public, max-age='.ServerDiscovery::CACHE_SECONDS]);
    }

    #[Unauthenticated]
    #[Endpoint('Get a public server page', "Returns what the public page of a server shows: its name, description, banner, game, version, online status and players, join address and votes. Only published pages of servers that are not suspended are found. The only address ever returned is the server's playit tunnel address; without one it is null. Cached for 30 seconds.")]
    #[UrlParam('slug', 'string', 'The part of the page address after /s/.', example: 'skyblock')]
    #[ResponseField('slug', 'string', 'The part of the page address after /s/.')]
    #[ResponseField('name', 'string', 'The server name.')]
    #[ResponseField('tagline', 'string', 'One line of plain text under the name, at most 80 characters; empty for none.')]
    #[ResponseField('description', 'string', 'The description in markdown, to be shown with a safe renderer; empty for none or when the owner hides it.')]
    #[ResponseField('banner', 'object', 'The banner colour and image.', schema: self::BANNER_SCHEMA)]
    #[ResponseField('game', 'string', 'The game, such as Minecraft, or null when it is not one the panel knows.', nullable: true)]
    #[ResponseField('software', 'string', 'The server type, such as Paper.', nullable: true)]
    #[ResponseField('version', 'string', "The game version, from the game itself or the server's settings, or null.", nullable: true)]
    #[ResponseField('status', 'string', 'The current state of the server.', schema: self::STATUS_SCHEMA)]
    #[ResponseField('players', 'object', 'The players online and the limit.', nullable: true, schema: self::PLAYERS_SCHEMA)]
    #[ResponseField('address', 'string', 'The playit address to join with, or null when the server has none.', nullable: true)]
    #[ResponseField('join_url', 'string', 'A link that adds the server in the game (Minecraft Bedrock), or null.', nullable: true)]
    #[ResponseField('votes', 'integer', 'The total votes.')]
    #[ResponseField('can_wake', 'boolean', 'Whether a visitor may wake the server now: it is sleeping and its owner allows it.')]
    #[ResponseField('theme', 'object', "The page's colours and button shape.", schema: self::THEME_SCHEMA)]
    #[ResponseField('sections', 'object', 'Which parts of the page are shown.', schema: self::SECTIONS_SCHEMA)]
    #[ResponseField('links', 'object', 'The Discord invite and website.', schema: self::LINKS_SCHEMA)]
    #[ScribeResponse(self::PAGE_EXAMPLE, description: 'Page returned.')]
    #[ScribeResponse(self::NOT_FOUND_ERROR, status: 404, description: 'No published page has that address.')]
    public function show(string $slug): JsonResponse
    {
        return new JsonResponse($this->pages->payload($this->find($slug)), headers: ['Cache-Control' => 'public, max-age='.PublicServerPages::CACHE_SECONDS]);
    }

    #[Unauthenticated]
    #[Endpoint('Vote for a public server page', 'Adds one vote. A visitor votes once every 24 hours per page, known by a keyed hash of their address and of a visitor cookie this endpoint sets; neither is stored as is. A vote that is not counted returns counted false and when the next one is possible. A page takes at most five votes a day from one /24 (IPv4) or /48 (IPv6) network. Must be sent from the panel itself (Origin or Referer), with a JSON body and the X-Requested-With: XMLHttpRequest header.')]
    #[UrlParam('slug', 'string', 'The part of the page address after /s/.', example: 'skyblock')]
    #[BodyParam('vote', 'boolean', 'Always true.', required: true, example: true)]
    #[ResponseField('counted', 'boolean', 'Whether this vote was counted.')]
    #[ResponseField('votes', 'integer', 'The total votes now.')]
    #[ResponseField('next_vote_at', 'string', 'When this visitor can vote for the page again.')]
    #[ScribeResponse(['counted' => true, 'votes' => 43, 'next_vote_at' => '2026-11-16T12:00:00+00:00'], description: 'Vote handled.')]
    #[ScribeResponse(self::NOT_FOUND_ERROR, status: 404, description: 'No published page has that address.')]
    #[ScribeResponse(self::CROSS_SITE_ERROR, status: 403, description: 'The request did not come from the panel.')]
    #[ScribeResponse(self::UNSUPPORTED_ERROR, status: 415, description: 'The request was not JSON with X-Requested-With.')]
    public function vote(Request $request, ServerPageVotes $votes, string $slug): JsonResponse
    {
        $this->assertJson($request);
        $page = $this->find($slug);
        $visitor = new ServerPageVisitor($request);

        $result = $votes->vote($page, $visitor);
        if ($result['counted']) {
            $this->pages->forget($page);
        }

        $response = new JsonResponse([
            'counted' => $result['counted'],
            'votes' => $result['votes'],
            'next_vote_at' => $result['next_vote_at']->toAtomString(),
        ], headers: ['Cache-Control' => 'no-store']);

        $cookie = $visitor->cookie();
        if ($cookie instanceof Cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    #[Unauthenticated]
    #[Endpoint('Wake a sleeping server', 'Starts a server that auto-sleep stopped, when its owner allows visitors to (403 otherwise). A server is woken at most once a minute and three times an hour, and one visitor wakes at most two servers an hour; when limited, 429 is returned with a Retry-After header. A server that is not sleeping is left alone and returns sleeping false. When the panel uses a captcha, its token is required. Must be sent from the panel itself (Origin or Referer), with a JSON body and the X-Requested-With: XMLHttpRequest header.')]
    #[UrlParam('slug', 'string', 'The part of the page address after /s/.', example: 'skyblock')]
    #[BodyParam('g-recaptcha-response', 'string', 'The captcha token (Turnstile action wake), required when the panel uses a captcha.', required: false, example: 'XXXX.DUMMY.TOKEN')]
    #[ResponseField('woken', 'boolean', 'Whether the server was started.')]
    #[ResponseField('sleeping', 'boolean', 'Whether the server is still sleeping.')]
    #[ScribeResponse(['woken' => true, 'sleeping' => false], description: 'Wake handled.')]
    #[ScribeResponse(self::TOO_MANY_ERROR, status: 429, description: 'Woken too recently; the Retry-After header says when to try again.')]
    #[ScribeResponse(self::NOT_FOUND_ERROR, status: 404, description: 'No published page has that address.')]
    #[ScribeResponse(self::WAKE_OFF_ERROR, status: 403, description: 'Visitors may not wake this server, or the request did not come from the panel.')]
    #[ScribeResponse(self::UNSUPPORTED_ERROR, status: 415, description: 'The request was not JSON with X-Requested-With.')]
    public function wake(Request $request, ServerPageWake $wake, string $slug): JsonResponse
    {
        $this->assertJson($request);
        $page = $this->find($slug);
        $server = $page->server ?? throw new NotFoundHttpException;
        throw_unless($page->allow_wake, AccessDeniedHttpException::class, self::WAKE_OFF_MESSAGE);

        // The panel's captcha (Turnstile or reCAPTCHA), when it has one; throws on a bad token.
        resolve(VerifyCaptcha::class)->handle($request, fn (Request $verified): Response => new Response, 'wake');

        $result = $wake->wake($server, new ServerPageVisitor($request));
        if ($result['woken']) {
            $this->pages->forget($page);
        }

        throw_if($result['retry_after'] !== null, TooManyRequestsHttpException::class, $result['retry_after'], 'This server was woken a moment ago. Please try again shortly.');

        return new JsonResponse(['woken' => $result['woken'], 'sleeping' => $result['sleeping']], headers: ['Cache-Control' => 'no-store']);
    }

    private function find(string $slug): ServerPage
    {
        $page = $this->pages->find($slug);

        return $page instanceof ServerPage && $page->server instanceof Server ? $page : throw new NotFoundHttpException;
    }

    /**
     * Votes and wakes come only from the panel's own page. The Origin (or the Referer when a
     * browser sends no Origin) must be the panel's host, and the body must be exactly
     * application/json with the X-Requested-With header: both force a CORS preflight, which
     * nothing here allows, so another site cannot send them from its visitors' browsers.
     */
    private function assertJson(Request $request): void
    {
        $source = $request->headers->get('Origin');
        if (! is_string($source) || $source === '' || $source === 'null') {
            $source = $request->headers->get('Referer');
        }

        $host = is_string($source) ? parse_url($source, PHP_URL_HOST) : null;
        $panel = parse_url(config()->string('app.url'), PHP_URL_HOST);
        throw_unless(is_string($host) && is_string($panel) && strcasecmp($host, $panel) === 0, AccessDeniedHttpException::class, self::ORIGIN_MESSAGE);

        $type = mb_strtolower(mb_trim(explode(';', (string) $request->headers->get('Content-Type', ''))[0]));
        throw_unless($type === 'application/json' && $request->ajax(), UnsupportedMediaTypeHttpException::class, self::JSON_MESSAGE);
    }
}
