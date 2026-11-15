<?php

declare(strict_types=1);

namespace Pterodactyl\Contracts\Captcha;

interface VerifiesCaptcha
{
    /**
     * Ask the provider whether the token the browser widget produced is valid.
     * Never throws: an unreachable provider counts as a failed verification.
     *
     * @param  'recaptcha'|'turnstile'  $provider
     * @return array{success: bool, hostname: string|null}
     */
    public function verify(string $provider, string $token, ?string $ip): array;
}
