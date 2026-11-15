<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Legal;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Enum\LegalDocument;
use Pterodactyl\Models\Setting;
use Pterodactyl\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * The Terms of Service, Privacy Policy and Refund Policy: stored as
 * "settings::monoforge:legal:{document}:{content,url,version,updated_at}" rows.
 *
 * They are read straight from the settings table (through its cache) rather than from
 * config(), so they apply on panels that load their settings from the environment only and
 * in tests, where the SettingsServiceProvider is not registered. With nothing stored a
 * document is the starter text in resources/legal at version 1.
 *
 * Users who signed up before acceptance was recorded have no accepted version; they count
 * as having accepted version 1, so only a version the administrator publishes with "Ask
 * players to accept again" (2 and up) asks them.
 */
final class LegalSettings
{
    /** The version every document starts at, and the one users without a record count as having accepted. */
    public const int INITIAL_VERSION = 1;

    /** Why a purchase is refused while updated documents wait to be accepted. */
    public const string ACCEPT_FIRST = 'Please accept the updated terms first.';

    /** When the starter texts were written; shown as "Last updated" until an administrator saves. */
    public const string DEFAULT_UPDATED_AT = '2026-11-20';

    /** The settings table stores values as TEXT (64 KiB); this leaves room for any real document. */
    public const int MAX_CONTENT_BYTES = 60_000;

    /** The document's markdown: what the administrator saved, or the starter text. */
    public static function content(LegalDocument $document): string
    {
        $content = self::stored($document, 'content');

        return mb_trim($content) !== '' ? $content : self::defaultContent($document);
    }

    /** Whether the document is still the shipped starter text. */
    public static function isDefault(LegalDocument $document): bool
    {
        return mb_trim(self::stored($document, 'content')) === '';
    }

    public static function defaultContent(LegalDocument $document): string
    {
        $path = resource_path('legal/'.$document->value.'.md');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /** The external page the document lives on instead, or an empty string when the panel hosts it. */
    public static function externalUrl(LegalDocument $document): string
    {
        $url = mb_trim(self::stored($document, 'url'));

        return self::isSafeUrl($url) ? $url : '';
    }

    /** Where links to the document point: the external page when one is set, else the panel's page. */
    public static function link(LegalDocument $document): string
    {
        $url = self::externalUrl($document);

        return $url !== '' ? $url : $document->path();
    }

    public static function version(LegalDocument $document): int
    {
        $version = (int) self::stored($document, 'version');

        return max(self::INITIAL_VERSION, $version);
    }

    public static function updatedAt(LegalDocument $document): CarbonImmutable
    {
        $stored = self::stored($document, 'updated_at');

        try {
            return CarbonImmutable::parse($stored !== '' ? $stored : self::DEFAULT_UPDATED_AT);
        } catch (Throwable) {
            return CarbonImmutable::parse(self::DEFAULT_UPDATED_AT);
        }
    }

    /** Only absolute https links are stored or followed. */
    public static function isSafeUrl(string $url): bool
    {
        return $url !== ''
            && str_starts_with(mb_strtolower($url), 'https://')
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && ! preg_match('/[\s<>"\'\\\\]/', $url);
    }

    /**
     * Stores a document. An empty content falls back to the starter text, an empty URL makes
     * the panel host the document, and `reaccept` raises the version so signed-in users accept
     * it again.
     */
    public static function save(LegalDocument $document, string $content, string $url, bool $reaccept): void
    {
        $version = self::version($document) + ($reaccept ? 1 : 0);

        Setting::put(self::key($document, 'content'), mb_trim($content) === '' ? '' : $content);
        Setting::put(self::key($document, 'url'), mb_trim($url));
        Setting::put(self::key($document, 'version'), (string) $version);
        Setting::put(self::key($document, 'updated_at'), CarbonImmutable::now()->toAtomString());
    }

    /**
     * The version of a document the user accepted; users without a record count as having
     * accepted the first one.
     */
    public static function acceptedVersion(User $user, LegalDocument $document): int
    {
        $column = match ($document) {
            LegalDocument::Terms => 'terms_accepted_version',
            LegalDocument::Privacy => 'privacy_accepted_version',
            LegalDocument::Refunds => null,
        };

        // Read from the attributes: a model created in this request has no value loaded for
        // the column, which strict mode would refuse to read through the property.
        $accepted = $column === null ? null : ($user->getAttributes()[$column] ?? null);

        return is_numeric($accepted) ? (int) $accepted : self::INITIAL_VERSION;
    }

    /**
     * The documents published since the user last accepted them.
     *
     * @return list<LegalDocument>
     */
    public static function pendingFor(User $user): array
    {
        return array_values(array_filter(
            LegalDocument::cases(),
            fn (LegalDocument $document): bool => $document->requiresAcceptance()
                && self::version($document) > self::acceptedVersion($user, $document),
        ));
    }

    /**
     * Refuses an acceptance of versions other than the current ones: a document published while
     * the form was open must be read before it can be accepted.
     *
     * @throws ValidationException
     */
    public static function ensureShownVersions(int $terms, int $privacy): void
    {
        $errors = [];
        if ($terms !== self::version(LegalDocument::Terms)) {
            $errors['terms_version'] = 'The Terms of Service changed while you were reading. Please review the new version.';
        }

        if ($privacy !== self::version(LegalDocument::Privacy)) {
            $errors['privacy_version'] = 'The Privacy Policy changed while you were reading. Please review the new version.';
        }

        throw_if($errors !== [], ValidationException::withMessages($errors));
    }

    /**
     * Refuses a purchase while the user has updated documents to accept.
     *
     * @throws ConflictHttpException
     */
    public static function ensureAccepted(User $user): void
    {
        throw_if(self::pendingFor($user) !== [], ConflictHttpException::class, self::ACCEPT_FIRST);
    }

    /** Records that the user accepted the current Terms of Service and Privacy Policy. */
    public static function recordAcceptance(User $user): void
    {
        $user->forceFill([
            'terms_accepted_version' => self::version(LegalDocument::Terms),
            'terms_accepted_at' => CarbonImmutable::now(),
            'privacy_accepted_version' => self::version(LegalDocument::Privacy),
        ])->save();
    }

    /**
     * What the frontend needs about every document: its title, where it lives, its version and
     * when it changed.
     *
     * @return array<string, array{title: string, url: string, external: bool, version: int, updatedAt: string}>
     */
    public static function summary(): array
    {
        $documents = [];
        foreach (LegalDocument::cases() as $document) {
            $documents[$document->value] = [
                'title' => $document->label(),
                'url' => self::link($document),
                'external' => self::externalUrl($document) !== '',
                'version' => self::version($document),
                'updatedAt' => self::updatedAt($document)->toAtomString(),
            ];
        }

        return $documents;
    }

    /**
     * The `legal` entry of the page bootstrap: every document, and for a signed-in user the ones
     * they still have to accept.
     *
     * @return array{documents: array<string, array{title: string, url: string, external: bool, version: int, updatedAt: string}>, pending: list<string>}
     */
    public static function sitePayload(?User $user): array
    {
        return [
            'documents' => self::summary(),
            'pending' => $user instanceof User
                ? array_map(fn (LegalDocument $document): string => $document->value, self::pendingFor($user))
                : [],
        ];
    }

    /**
     * The panel-hosted page of a document.
     *
     * @return array{document: string, title: string, content: string, version: int, updatedAt: string}
     */
    public static function pagePayload(LegalDocument $document): array
    {
        return [
            'document' => $document->value,
            'title' => $document->label(),
            'content' => self::content($document),
            'version' => self::version($document),
            'updatedAt' => self::updatedAt($document)->toAtomString(),
        ];
    }

    private static function key(LegalDocument $document, string $field): string
    {
        return 'settings::monoforge:legal:'.$document->value.':'.$field;
    }

    private static function stored(LegalDocument $document, string $field): string
    {
        try {
            return (string) Setting::cached(self::key($document, $field));
        } catch (Throwable) {
            // No database (yet): the starter texts still render.
            return '';
        }
    }
}
