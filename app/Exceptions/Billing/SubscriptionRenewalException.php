<?php

declare(strict_types=1);

namespace Pterodactyl\Exceptions\Billing;

use Pterodactyl\Exceptions\DisplayException;

/**
 * A manual renewal or subscription change that cannot be done, with the reason for the user.
 */
class SubscriptionRenewalException extends DisplayException {}
