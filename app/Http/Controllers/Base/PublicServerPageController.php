<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
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
#[Subgroup('Server pages', 'The shareable public pages of servers at /s/{slug}.')]
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

    private const array PAGE_EXAMPLE = [
        'slug' => 'skyblock',
        'name' => 'Skyblock Island',
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
    #[Endpoint('Get a public server page', "Returns what the public page of a server shows: its name, description, banner, game, version, online status and players, join address and votes. Only published pages of servers that are not suspended are found. The only address ever returned is the server's playit tunnel address; without one it is null. Cached for 30 seconds.")]
    #[UrlParam('slug', 'string', 'The part of the page address after /s/.', example: 'skyblock')]
    #[ResponseField('slug', 'string', 'The part of the page address after /s/.')]
    #[ResponseField('name', 'string', 'The server name.')]
    #[ResponseField('description', 'string', 'The description in markdown, to be shown with a safe renderer; empty for none.')]
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
