<?php

declare(strict_types=1);

namespace Pterodactyl\Exceptions\Billing;

use Pterodactyl\Exceptions\DisplayException;

/**
 * A shop quote asked for something the store cannot sell: a disabled period or add-on,
 * too many add-on units, or a node the plan does not allow.
 */
class InvalidQuoteException extends DisplayException {}
