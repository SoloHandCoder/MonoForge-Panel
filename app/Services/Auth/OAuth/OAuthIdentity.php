<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Auth\OAuth;

use Pterodactyl\Enum\OAuthProvider;

/**
 * Who the provider says signed in. `email` is only set when the provider has verified it.
 */
final readonly class OAuthIdentity
{
    public function __construct(
        public OAuthProvider $provider,
        public string $id,
        public ?string $username,
        public ?string $displayName,
        public ?string $email,
        public ?string $avatar,
    ) {}
}
