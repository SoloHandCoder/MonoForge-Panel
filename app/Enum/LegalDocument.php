<?php

declare(strict_types=1);

namespace Pterodactyl\Enum;

/**
 * The panel's legal documents, each served at /legal/{value}.
 */
enum LegalDocument: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';
    case Refunds = 'refunds';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $document): string => $document->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Terms => 'Terms of Service',
            self::Privacy => 'Privacy Policy',
            self::Refunds => 'Refund Policy',
        };
    }

    /** The public page of the document on this panel. */
    public function path(): string
    {
        return '/legal/'.$this->value;
    }

    /**
     * Whether users accept this document (at sign-up, and again when its version goes up).
     * The refund policy is part of the terms and only shown at checkout.
     */
    public function requiresAcceptance(): bool
    {
        return $this !== self::Refunds;
    }
}
