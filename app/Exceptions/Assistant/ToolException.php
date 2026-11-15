<?php

declare(strict_types=1);

namespace Pterodactyl\Exceptions\Assistant;

use RuntimeException;

/**
 * A tool call failed for a reason that is safe to show the user and the model, such as a
 * missing permission or a file that does not exist.
 */
class ToolException extends RuntimeException {}
