<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pterodactyl\Contracts\Captcha\VerifiesCaptcha;
use Pterodactyl\Events\Auth\FailedCaptcha;
use Pterodactyl\Services\Captcha\CaptchaSettings;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Rejects requests without a valid token from the panel's captcha provider
 * (reCAPTCHA or Cloudflare Turnstile), and lets everything through when the
 * captcha is turned off. Registered as both "captcha" and "recaptcha"; a parameter
 * (e.g. "captcha:register") names the Turnstile action the widget must have used.
 */
class VerifyCaptcha
{
    public function __construct(private readonly VerifiesCaptcha $verifier) {}

    /**
     * @param  Closure(Request): \Symfony\Component\HttpFoundation\Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $action = null): \Symfony\Component\HttpFoundation\Response
    {
        $provider = CaptchaSettings::provider();
        if ($provider === CaptchaSettings::NONE) {
            return $next($request);
        }

        $token = $this->token($request);
        $result = $token === ''
            ? ['success' => false, 'hostname' => null, 'action' => null]
            : $this->verifier->verify($provider, $token, $request->ip());

        if ($result['success']
            && $this->domainValid($provider, $result['hostname'], $request)
            && $this->actionValid($provider, $result['action'], $action)) {
            return $next($request);
        }

        event(new FailedCaptcha($request->ip() ?? 'unknown', $result['hostname'] ?? 'unknown'));

        throw new HttpException(Response::HTTP_BAD_REQUEST, $provider === CaptchaSettings::TURNSTILE
            ? 'Failed to validate captcha data.'
            : 'Failed to validate reCAPTCHA data.');
    }

    /**
     * The panel's forms send every provider's token as "g-recaptcha-response";
     * Turnstile's own field name is accepted too.
     */
    private function token(Request $request): string
    {
        foreach (['g-recaptcha-response', 'cf-turnstile-response'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * The domain the captcha was solved on must be this request's, so a token solved
     * on another site is useless here. Always checked for Turnstile; for reCAPTCHA it
     * follows "recaptcha.verify_domain".
     */
    private function domainValid(string $provider, ?string $hostname, Request $request): bool
    {
        if ($provider === CaptchaSettings::RECAPTCHA && ! config()->boolean('recaptcha.verify_domain')) {
            return true;
        }

        return $hostname !== null && $hostname === parse_url($request->url(), PHP_URL_HOST);
    }

    /**
     * Turnstile tokens carry the action of the form that produced them, so a token
     * solved on the login form cannot be spent on sign-up. reCAPTCHA v2 has no actions.
     */
    private function actionValid(string $provider, ?string $returned, ?string $expected): bool
    {
        return $provider !== CaptchaSettings::TURNSTILE || $expected === null || $returned === $expected;
    }
}
