<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Unauthenticated;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Contracts\Users\CreatesUsers;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Auth\RegisterRequest;
use Pterodactyl\Services\Billing\ReferralService;
use Pterodactyl\Services\Billing\ReferralSettings;
use Pterodactyl\Services\Billing\TrialFingerprint;
use Pterodactyl\Services\Legal\LegalSettings;
use Throwable;

#[Group('Authentication', 'Browser session authentication endpoints used by the panel frontend.', authenticated: false)]
class RegisterController extends Controller
{
    private const array REGISTER_COMPLETE_EXAMPLE = [
        'data' => [
            'complete' => true,
            'intended' => '/',
            'user' => [
                'uuid' => '9a8b7c6d-5e4f-4321-9876-123456789abc',
                'username' => 'player',
                'email' => 'player@example.com',
                'name_first' => 'player',
                'name_last' => 'player',
                'language' => 'en',
                'root_admin' => false,
                'use_totp' => false,
                'created_at' => '2026-11-15T12:00:00+00:00',
                'updated_at' => '2026-11-15T12:00:00+00:00',
                'identifier' => 'usr_1a2b3c4d',
            ],
        ],
    ];

    /**
     * Create a normal account for a visitor and sign them in, when the panel
     * allows sign-up.
     *
     * @throws Throwable
     */
    #[Unauthenticated]
    #[Endpoint('Register', 'Creates a new account when sign-up is enabled and signs the browser session in.')]
    #[BodyParam('username', 'string', 'The new account username.', required: true, example: 'player')]
    #[BodyParam('email', 'string', 'The new account email address.', required: true, example: 'player@example.com')]
    #[BodyParam('password', 'string', 'The new account password.', required: true, example: 'correct-horse-battery-staple')]
    #[BodyParam('password_confirmation', 'string', 'The password again.', required: true, example: 'correct-horse-battery-staple')]
    #[BodyParam('terms', 'boolean', 'Whether the visitor agrees to the Terms of Service and Privacy Policy. Must be true.', required: true, example: true)]
    #[BodyParam('terms_version', 'integer', 'The Terms of Service version the form showed. Must be the current one.', required: true, example: 1)]
    #[BodyParam('privacy_version', 'integer', 'The Privacy Policy version the form showed. Must be the current one.', required: true, example: 1)]
    #[BodyParam('g-recaptcha-response', 'string', 'The captcha token (reCAPTCHA or Turnstile) when a captcha is enabled.', required: false, example: '03AFcWeA...', nullable: true)]
    #[ScribeResponse(self::REGISTER_COMPLETE_EXAMPLE, status: 201, description: 'The account was created and a browser session was established.')]
    #[ScribeResponse(status: 404, description: 'Sign-up is disabled.')]
    #[ScribeResponse(status: 429, description: 'Too many sign-up attempts from this address or for this email.')]
    public function __invoke(RegisterRequest $request, CreatesUsers $users, CompletesLogins $logins, ReferralService $referrals): JsonResponse
    {
        // Only the Terms of Service and Privacy Policy versions the form showed can be accepted.
        LegalSettings::ensureShownVersions($request->integer('terms_version'), $request->integer('privacy_version'));

        $username = $request->string('username')->toString();

        // Sign-up only asks for a username; the account's name starts out as that username.
        $user = $users->create([
            'username' => $username,
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
            'name_first' => $username,
            'name_last' => $username,
        ], notify: false);

        // The network it signed up from, hashed: one free trial per network (TrialService).
        $user->forceFill(['signup_subnet_hash' => TrialFingerprint::subnet($request->ip())])->save();

        // The form cannot be sent without ticking the Terms of Service and Privacy Policy.
        LegalSettings::recordAcceptance($user);

        // A visitor who came through a referral link (/r/{code}) carries its cookie.
        $referrals->recordSignup($user, $request->cookies->get(ReferralSettings::COOKIE), $request->ip());

        return (new JsonResponse(['data' => $logins->complete($user)->toResponseData()], JsonResponse::HTTP_CREATED))
            ->withCookie(cookie()->forget(ReferralSettings::COOKIE));
    }
}
