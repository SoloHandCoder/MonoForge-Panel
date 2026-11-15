<?php

declare(strict_types=1);

namespace Pterodactyl\Exceptions\Service\Auth;

use RuntimeException;

/**
 * A social login or link that cannot go ahead. The message is safe to show the user and
 * never contains tokens or provider responses.
 */
final class OAuthException extends RuntimeException {}
