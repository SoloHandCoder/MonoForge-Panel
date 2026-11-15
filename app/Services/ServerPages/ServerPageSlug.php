<?php

declare(strict_types=1);

namespace Pterodactyl\Services\ServerPages;

use Illuminate\Validation\Rule;
use Pterodactyl\Models\Share\ServerPage;

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

    /**
     * @return ValidationRuleSet
     */
    public static function rules(?ServerPage $ignore = null): array
    {
        return [
            'required',
            'string',
            'min:'.self::MIN,
            'max:'.self::MAX,
            'regex:/\A'.self::PATTERN.'\z/',
            Rule::notIn(self::RESERVED),
            Rule::unique('server_pages', 'slug')->ignore($ignore?->id),
        ];
    }

    /** Whether a value has the shape of a slug, before any lookup. */
    public static function valid(string $slug): bool
    {
        $length = mb_strlen($slug);

        return $length >= self::MIN && $length <= self::MAX && preg_match('/\A'.self::PATTERN.'\z/', $slug) === 1;
    }
}
