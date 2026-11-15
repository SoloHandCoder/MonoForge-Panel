<?php

declare(strict_types=1);

namespace Pterodactyl\Services\ServerPages;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Who a visitor of a public server page is, without ever keeping who they are: their address and
 * their visitor cookie only leave here as HMACs with the app key, a purpose and a scope (such as
 * the page), so the stored keys cannot be turned back into an address or matched across pages.
 * An IPv6 address counts by its /64, the block one connection is normally given.
 */
final class ServerPageVisitor
{
    public const string COOKIE = 'mf_visitor';

    private const int COOKIE_MINUTES = 60 * 24 * 365;

    private ?string $token;

    private bool $issued = false;

    public function __construct(private readonly Request $request)
    {
        $cookie = $request->cookies->get(self::COOKIE);
        $this->token = is_string($cookie) && preg_match('/\A[a-f0-9]{32}\z/', $cookie) === 1 ? $cookie : null;
    }

    /** The keyed hash of the visitor's address (IPv6 by its /48) for a purpose and scope. */
    public function addressKey(string $purpose, string $scope): string
    {
        return $this->hash('address', $purpose, $scope, $this->network(32, 6));
    }

    /** The keyed hash of the visitor's network: its /24 for IPv4, its /48 for IPv6. */
    public function networkKey(string $purpose, string $scope): string
    {
        return $this->hash('network', $purpose, $scope, $this->network(24, 6));
    }

    /** The keyed hash of the visitor's cookie for a purpose and scope; a visitor without one is given one. */
    public function cookieKey(string $purpose, string $scope): string
    {
        if ($this->token === null) {
            $this->token = bin2hex(random_bytes(16));
            $this->issued = true;
        }

        return $this->hash('cookie', $purpose, $scope, $this->token);
    }

    /** The cookie to send back when this request gave the visitor a new one. */
    public function cookie(): ?Cookie
    {
        if (! $this->issued || $this->token === null) {
            return null;
        }

        return new Cookie(self::COOKIE, $this->token, time() + self::COOKIE_MINUTES * 60, '/', null, $this->request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }

    /**
     * The address cut to a prefix: IPv4 to $ipv4Bits (32 keeps it whole), IPv6 to $ipv6Bytes bytes
     * (6 bytes is the /48 a single customer is usually given).
     */
    private function network(int $ipv4Bits, int $ipv6Bytes): string
    {
        $address = (string) $this->request->ip();
        $packed = @inet_pton($address);
        if ($packed === false) {
            return $address;
        }

        if (mb_strlen($packed) === 16) {
            return bin2hex(mb_substr($packed, 0, $ipv6Bytes)).'::/'.($ipv6Bytes * 8);
        }

        $bytes = intdiv($ipv4Bits, 8);

        return bin2hex(mb_substr($packed, 0, $bytes)).'/'.$ipv4Bits;
    }

    private function hash(string $kind, string $purpose, string $scope, string $value): string
    {
        return hash_hmac('sha256', implode('|', ['server-page', $kind, $purpose, $scope, $value]), config()->string('app.key'));
    }
}
