<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Billing\ReferralService;
use Pterodactyl\Services\Billing\ReferralSettings;

/**
 * A referral link (/r/{code}): remembers the code in a cookie for 30 days, so a sign-up in that
 * time is recorded as referred, and sends the visitor on to sign up. The answer is the same
 * redirect whether or not the code exists, so links cannot be probed for.
 */
class ReferralLinkController extends Controller
{
    public function __invoke(Request $request, ReferralService $referrals, string $code): RedirectResponse
    {
        $signedIn = $request->user() instanceof User;
        $registration = config()->boolean('monoforge.auth.registration.enabled');
        $response = new RedirectResponse($signedIn ? '/' : ($registration ? '/auth/register' : '/auth/login'));

        $referrer = $signedIn || ! ReferralSettings::enabled() ? null : $referrals->referrerFor($code);
        if (! $referrer instanceof User) {
            return $response;
        }

        return $response->withCookie(cookie(
            ReferralSettings::COOKIE,
            mb_strtoupper($code),
            ReferralSettings::COOKIE_DAYS * 24 * 60,
            httpOnly: true,
            sameSite: 'lax',
        ));
    }
}
