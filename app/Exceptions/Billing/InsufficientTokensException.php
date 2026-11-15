<?php

declare(strict_types=1);

namespace Pterodactyl\Exceptions\Billing;

use Pterodactyl\Exceptions\DisplayException;

/**
 * STAND-IN for Task Q (phase 3). Task Q owns this class: on merge, keep Task Q's version.
 *
 * Thrown by TokenWallet::debit() when the balance would go below zero.
 */
class InsufficientTokensException extends DisplayException {}
