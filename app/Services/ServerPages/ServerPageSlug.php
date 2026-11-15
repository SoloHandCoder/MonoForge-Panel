<?php

declare(strict_types=1);

namespace Pterodactyl\Services\ServerPages;

use Closure;
use Illuminate\Validation\Rule;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Models\Share\ServerPageSlugHold;

/**
 * The rules for the part of a public page's address after /s/: 3 to 32 lowercase letters,
 * digits and single hyphens, starting and ending with a letter or digit, unique, and none of
 * the words that would look like the panel's own pages or could mislead a visitor.
 */
final class ServerPageSlug
{
    public const int MIN = 3;

    public const int MAX = 32;

    public const string PATTERN = '[a-z0-9](?:[a-z0-9]|-(?!-))*[a-z0-9]';

    /** Words a slug may not be: the panel's pages, staff roles and names a scam would pick. */
    public const array RESERVED = [
        'about', 'account', 'admin', 'administrator', 'api', 'app', 'assets', 'auth', 'banner', 'billing',
        'blog', 'contact', 'dashboard', 'docs', 'download', 'edit', 'extensions', 'faq', 'help', 'home',
        'index', 'legal', 'login', 'logout', 'mod', 'moderator', 'monoforge', 'new', 'null', 'official',
        'owner', 'panel', 'password', 'payment', 'privacy', 'pterodactyl', 'register', 'root', 'security',
        'server', 'servers', 'settings', 'shop', 'signin', 'signup', 'staff', 'status', 'store', 'support',
        'system', 'team', 'terms', 'undefined', 'upload', 'user', 'users', 'vote', 'wake', 'www',
    ];

    /** Words a slug may not even contain (brand and role names), unless a root admin sets it. */
    public const array RESERVED_PARTS = [
        'monoforge', 'admin', 'support', 'staff', 'official', 'login', 'panel', 'billing', 'status', 'store',
    ];

    public const string RESERVED_PART_MESSAGE = 'That address uses a word reserved for the panel (such as support, staff or official). Please choose another one.';

    public const string HELD_MESSAGE = 'That address belonged to another page until recently. Please choose another one.';

    /**
     * @return ValidationRuleSet
     */
    public static function rules(?ServerPage $ignore = null, bool $admin = false, ?int $serverId = null): array
    {
        return [
            'required',
            'string',
            'min:'.self::MIN,
            'max:'.self::MAX,
            'regex:/\A'.self::PATTERN.'\z/',
            Rule::notIn(self::RESERVED),
            Rule::unique('server_pages', 'slug')->ignore($ignore?->id),
            function (string $attribute, mixed $value, Closure $fail) use ($admin, $serverId): void {
                if (! is_string($value)) {
                    return;
                }

                if (! $admin && self::containsReservedPart($value)) {
                    $fail(self::RESERVED_PART_MESSAGE);

                    return;
                }

                if (ServerPageSlugHold::heldForOther($value, $serverId)) {
                    $fail(self::HELD_MESSAGE);
                }
            },
        ];
    }

    /** Whether the slug contains a brand or role word anywhere (monoforge-support, officialstaff). */
    public static function containsReservedPart(string $slug): bool
    {
        foreach (self::RESERVED_PARTS as $word) {
            if (str_contains($slug, $word)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a value has the shape of a slug, before any lookup. */
    public static function valid(string $slug): bool
    {
        $length = mb_strlen($slug);

        return $length >= self::MIN && $length <= self::MAX && preg_match('/\A'.self::PATTERN.'\z/', $slug) === 1;
    }
}
