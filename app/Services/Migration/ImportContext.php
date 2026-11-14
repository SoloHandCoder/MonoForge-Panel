<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Migration;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;

/**
 * Carries what a transform needs that is not in the row itself: the source
 * panel's encrypter (built from that panel's APP_KEY) and this panel's.
 *
 * Node daemon tokens, database and database host passwords, TOTP secrets and
 * API key tokens are encrypted with the panel's APP_KEY. They cannot be copied
 * verbatim: they are decrypted with the old key and re-encrypted with ours, or
 * the imported panel could not talk to its own nodes.
 *
 * Adapted from the m12labs panel importer (MIT licensed).
 */
final readonly class ImportContext
{
    public function __construct(
        private Encrypter $source,
        private Encrypter $target,
    ) {}

    /**
     * Re-wrap a secret from the source panel's key to ours. Null and empty values
     * pass through. A value that will not decrypt is fatal: writing a secret
     * nothing can read back is worse than stopping.
     *
     * @throws DecryptException
     */
    public function rewrap(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return $this->target->encrypt($this->source->decrypt($value));
    }

    /**
     * Whether a value decrypts with the source key. Used by the pre-flight probe
     * to catch a wrong source key before anything is written.
     */
    public function canDecrypt(?string $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        try {
            $this->source->decrypt($value);
        } catch (DecryptException) {
            return false;
        }

        return true;
    }
}
