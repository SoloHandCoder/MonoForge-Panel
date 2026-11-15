<?php

declare(strict_types=1);

namespace Pterodactyl\Exceptions\Billing;

use Pterodactyl\Exceptions\DisplayException;

/**
 * Tebex could not start a checkout. The message is safe to show to the player; the cause is
 * logged without any credentials.
 */
class TebexCheckoutException extends DisplayException {}
