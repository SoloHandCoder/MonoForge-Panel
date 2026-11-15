<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out the client address of a request.
 *
 * Forwarding headers are only believed when the direct peer (REMOTE_ADDR) is a trusted
 * proxy: loopback (cloudflared and nginx on the same host) or an address listed in
 * TRUSTED_PROXIES. From such a peer the Cloudflare "CF-Connecting-IP" header wins, then
 * X-Forwarded-For. From any other peer both are ignored, so they cannot be spoofed.
 *
 * Only X-Forwarded-For is trusted; the host, port and scheme still come from the request.
 */
class TrustProxies extends BaseTrustProxies
{
    /** Loopback ranges that are always trusted. */
    public const array LOOPBACK = ['127.0.0.0/8', '::1'];

    public const string CLOUDFLARE_HEADER = 'CF-Connecting-IP';

    /** Only X-Forwarded-For is believed from a trusted proxy. */
    private const int TRUSTED_HEADERS = Request::HEADER_X_FORWARDED_FOR;

    /** @var int */
    protected $headers = self::TRUSTED_HEADERS;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $trusted = $this->trustedProxies();
        $peer = $request->server->getString('REMOTE_ADDR');

        $request::setTrustedProxies([], self::TRUSTED_HEADERS);

        if ($peer === '' || ! IpUtils::checkIp($peer, $trusted)) {
            return $next($request);
        }

        $cloudflare = mb_trim($request->headers->get(self::CLOUDFLARE_HEADER) ?? '');
        if ($cloudflare !== '' && filter_var($cloudflare, FILTER_VALIDATE_IP) !== false) {
            // Cloudflare sets this header itself at the edge, so it is the client address.
            $request->headers->set('X-Forwarded-For', $cloudflare);
        }

        $request::setTrustedProxies($trusted, self::TRUSTED_HEADERS);

        return $next($request);
    }

    /**
     * Loopback plus the TRUSTED_PROXIES list. "*" and "**" trust every peer.
     *
     * @return list<string>
     */
    public function trustedProxies(): array
    {
        $configured = config('trustedproxy.proxies');
        if ($configured === '*' || $configured === '**') {
            return ['0.0.0.0/0', '::/0'];
        }

        $extra = [];
        foreach ((array) $configured as $value) {
            foreach (explode(',', (string) $value) as $entry) {
                $entry = mb_trim($entry);
                if ($entry !== '' && $entry !== 'REMOTE_ADDR') {
                    $extra[] = $entry;
                }
            }
        }

        return array_values(array_unique([...self::LOOPBACK, ...$extra]));
    }
}
