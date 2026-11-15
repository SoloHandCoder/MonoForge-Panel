<?php

declare(strict_types=1);

namespace Pterodactyl\Exceptions\Assistant;

use RuntimeException;

/**
 * The AI provider could not answer. The message is safe to show: it never contains the
 * API key, the prompt or the provider's raw response.
 */
class ChatProviderException extends RuntimeException {}
