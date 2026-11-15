<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Captcha;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Contracts\Captcha\VerifiesCaptcha;

/**
 * Verifies tokens against Google reCAPTCHA or Cloudflare Turnstile. Both take the
 * same form-encoded "secret" and "response" and answer with "success" and "hostname";
 * Turnstile also returns the "action" the widget was rendered with.
 */
final readonly class HttpCaptchaVerifier implements VerifiesCaptcha
{
    private const array FAILED = ['success' => false, 'hostname' => null, 'action' => null];

    public function verify(string $provider, string $token, ?string $ip): array
    {
        [$url, $secret] = $provider === CaptchaSettings::TURNSTILE
            ? [config()->string('monoforge.captcha.turnstile.domain'), config()->string('monoforge.captcha.turnstile.secret_key')]
            : [config()->string('recaptcha.domain'), config()->string('recaptcha.secret_key')];

        if ($secret === '' || $token === '') {
            return self::FAILED;
        }

        try {
            $response = Http::asForm()->timeout(5)->connectTimeout(2)->post($url, array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ], fn (?string $value): bool => $value !== null));
        } catch (ConnectionException) {
            return self::FAILED;
        }

        $decoded = $response->successful() ? $response->json() : null;
        if (! is_array($decoded)) {
            return self::FAILED;
        }

        $hostname = $decoded['hostname'] ?? null;
        $action = $decoded['action'] ?? null;

        return [
            'success' => ($decoded['success'] ?? false) === true,
            'hostname' => is_string($hostname) ? $hostname : null,
            'action' => is_string($action) ? $action : null,
        ];
    }
}
