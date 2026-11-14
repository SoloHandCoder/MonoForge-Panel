<?php

declare(strict_types=1);

namespace Pterodactyl\Exceptions\Service\MonoForge;

use RuntimeException;

/**
 * A MonoForge self-update could not be completed. Its message is shown to administrators.
 */
class UpdateFailedException extends RuntimeException {}
