<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Auth\OAuth;

use Pterodactyl\Enum\OAuthProvider;

/**
 * An authorization request waiting in the session for the provider to send the user back.
 */
final readonly class OAuthPending
{
    public const string INTENT_LOGIN = 'login';

    public const string INTENT_LINK = 'link';

    public function __construct(
        public OAuthProvider $provider,
        public string $state,
        public ?string $verifier,
        public string $intent,
        /** The signed-in user who asked to link, for the link intent. */
        public ?int $userId,
        public int $expiresAt,
    ) {}

    public function isLink(): bool
    {
        return $this->intent === self::INTENT_LINK;
    }
}
