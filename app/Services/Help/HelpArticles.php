<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Help;

use LogicException;
use Pterodactyl\Models\HelpArticle;
use Throwable;

/**
 * The help center at /help. Its articles are markdown files shipped in resources/help/{slug}.md,
 * each starting with a front matter block (title, category, order, summary and, for articles only
 * root administrators see under Admin > Help, `audience: admin`).
 *
 * Administrators change them in Admin > Settings > Help: a `help_articles` row with the slug of a
 * shipped article overrides it field by field (a null column keeps the shipped value, so updates
 * of the starter text still reach fields nobody changed), can turn it off, and is deleted to reset
 * the article. A row with any other slug is an article of its own, always for players.
 *
 * @phpstan-type HelpAudience 'public'|'admin'
 * @phpstan-type HelpDefault array{slug: string, title: string, category: string, summary: string, content: string, order: int, audience: HelpAudience}
 * @phpstan-type HelpEntry array{slug: string, title: string, category: string, summary: string, content: string, order: int, audience: HelpAudience, enabled: bool, shipped: bool, overridden: bool}
 * @phpstan-type HelpValues array{title: string, category: string, summary: string, content: string, order: int, enabled: bool}
 */
final class HelpArticles
{
    /** A slug: lowercase words joined by single hyphens, as in /help/joining-your-server. */
    public const string SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public const int MAX_SLUG = 64;

    public const int MAX_TITLE = 120;

    public const int MAX_CATEGORY = 60;

    public const int MAX_SUMMARY = 300;

    /** Bytes of markdown in one article; the shipped ones are a few KiB. */
    public const int MAX_CONTENT_BYTES = 60_000;

    /** Articles an administrator may add on top of the shipped ones, which keeps the public list small. */
    public const int MAX_CUSTOM = 100;

    /** Where an added article without a category is listed. */
    public const string DEFAULT_CATEGORY = 'More help';

    /** Where an added article without an order goes: after the shipped ones. */
    public const int DEFAULT_ORDER = 1000;

    /** @var array<string, HelpDefault>|null */
    private static ?array $defaults = null;

    public static function isSlug(string $slug): bool
    {
        return mb_strlen($slug) <= self::MAX_SLUG && preg_match('/^'.self::SLUG_PATTERN.'$/D', $slug) === 1;
    }

    /**
     * The shipped articles by slug, read once per process.
     *
     * @return array<string, HelpDefault>
     */
    public static function defaults(): array
    {
        if (self::$defaults !== null) {
            return self::$defaults;
        }

        $defaults = [];
        foreach (glob(resource_path('help/*.md')) ?: [] as $path) {
            $slug = basename($path, '.md');
            $article = self::isSlug($slug) ? self::parse($slug, (string) file_get_contents($path)) : null;
            if ($article !== null) {
                $defaults[$slug] = $article;
            }
        }

        return self::$defaults = $defaults;
    }

    /** Forgets the shipped articles read so far (tests that change resources/help). */
    public static function flush(): void
    {
        self::$defaults = null;
    }

    /**
     * Reads a shipped article: its front matter, then the markdown. Null when the front matter
     * is missing or has no title.
     *
     * @return HelpDefault|null
     */
    public static function parse(string $slug, string $source): ?array
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        if (preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $source, $matches) !== 1) {
            return null;
        }

        $meta = [];
        foreach (explode("\n", $matches[1]) as $line) {
            if (preg_match('/^([a-z_]+):\s*(.*?)\s*$/', $line, $pair) === 1) {
                $meta[$pair[1]] = self::unquote($pair[2]);
            }
        }

        $title = $meta['title'] ?? '';
        if ($title === '') {
            return null;
        }

        return [
            'slug' => $slug,
            'title' => $title,
            'category' => ($meta['category'] ?? '') !== '' ? $meta['category'] : self::DEFAULT_CATEGORY,
            'summary' => $meta['summary'] ?? '',
            'content' => mb_ltrim($matches[2], "\n"),
            'order' => is_numeric($meta['order'] ?? null) ? (int) $meta['order'] : self::DEFAULT_ORDER,
            'audience' => ($meta['audience'] ?? '') === 'admin' ? 'admin' : 'public',
        ];
    }

    /**
     * Every article with the administrator's changes, for both audiences and turned off ones
     * included, in help center order.
     *
     * @return list<HelpEntry>
     */
    public function all(): array
    {
        $rows = $this->rows();
        $entries = [];

        foreach (self::defaults() as $slug => $default) {
            $entries[] = $this->merge($default, $rows[$slug] ?? null);
            unset($rows[$slug]);
        }

        foreach ($rows as $row) {
            $entries[] = $this->custom($row);
        }

        usort($entries, fn (array $a, array $b): int => [$a['order'], mb_strtolower($a['title']), $a['slug']] <=> [$b['order'], mb_strtolower($b['title']), $b['slug']]);

        return $entries;
    }

    /**
     * The articles that are turned on for an audience, in help center order.
     *
     * @param  HelpAudience  $audience
     * @return list<HelpEntry>
     */
    public function published(string $audience = 'public'): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (array $entry): bool => $entry['enabled'] && $entry['audience'] === $audience,
        ));
    }

    /**
     * One turned-on article of an audience, or null.
     *
     * @param  HelpAudience  $audience
     * @return HelpEntry|null
     */
    public function find(string $slug, string $audience = 'public'): ?array
    {
        foreach ($this->published($audience) as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * One article, turned off ones included, or null when there is none with this slug.
     *
     * @return HelpEntry|null
     */
    public function entry(string $slug): ?array
    {
        foreach ($this->all() as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /** How many articles administrators added on top of the shipped ones. */
    public function customCount(): int
    {
        return HelpArticle::query()->whereNotIn('slug', array_keys(self::defaults()))->count();
    }

    /**
     * Stores an article. For a shipped article a value equal to the shipped one (or blank) is
     * stored as null, so it keeps following the shipped text.
     *
     * @param  HelpValues  $values
     * @return HelpEntry
     */
    public function save(string $slug, array $values): array
    {
        $default = self::defaults()[$slug] ?? null;

        HelpArticle::query()->updateOrCreate(['slug' => $slug], [
            'title' => $this->changed($values['title'], $default['title'] ?? null),
            'category' => $this->changed($values['category'], $default['category'] ?? null),
            'summary' => $this->changed($values['summary'], $default['summary'] ?? null),
            'content' => $this->changed($values['content'], $default['content'] ?? null, trim: false),
            'sort_order' => $default !== null && $values['order'] === $default['order'] ? null : $values['order'],
            'enabled' => $values['enabled'],
        ]);

        return $this->entry($slug) ?? throw new LogicException('The saved help article could not be read back.');
    }

    /** Resets a shipped article to the starter text, or removes an added one. */
    public function reset(string $slug): void
    {
        HelpArticle::query()->where('slug', $slug)->delete();
    }

    /** A front matter value without one pair of surrounding quotes. */
    private static function unquote(string $value): string
    {
        $first = mb_substr($value, 0, 1);

        return mb_strlen($value) >= 2 && ($first === '"' || $first === "'") && str_ends_with($value, $first)
            ? mb_substr($value, 1, -1)
            : $value;
    }

    /**
     * What to store for a field: null keeps the shipped value (a blank value, or the shipped one
     * again), a one-line field is stored trimmed and the markdown as written.
     */
    private function changed(string $value, ?string $shipped, bool $trim = true): ?string
    {
        $trimmed = mb_trim($value);
        if ($trimmed === '' || ($shipped !== null && $trimmed === mb_trim($shipped))) {
            return null;
        }

        return $trim ? $trimmed : $value;
    }

    /**
     * @param  HelpDefault  $default
     * @return HelpEntry
     */
    private function merge(array $default, ?HelpArticle $row): array
    {
        $pick = fn (?string $value, string $fallback): string => $value !== null && mb_trim($value) !== '' ? $value : $fallback;

        return [
            ...$default,
            'title' => $pick($row?->title, $default['title']),
            'category' => $pick($row?->category, $default['category']),
            'summary' => $pick($row?->summary, $default['summary']),
            'content' => $pick($row?->content, $default['content']),
            'order' => $row->sort_order ?? $default['order'],
            'enabled' => ! $row instanceof HelpArticle || $row->enabled,
            'shipped' => true,
            'overridden' => $row instanceof HelpArticle,
        ];
    }

    /**
     * @return HelpEntry
     */
    private function custom(HelpArticle $row): array
    {
        $category = mb_trim($row->category ?? '');
        $title = mb_trim($row->title ?? '');

        return [
            'slug' => $row->slug,
            'title' => $title !== '' ? $title : $row->slug,
            'category' => $category !== '' ? $category : self::DEFAULT_CATEGORY,
            'summary' => $row->summary ?? '',
            'content' => $row->content ?? '',
            'order' => $row->sort_order ?? self::DEFAULT_ORDER,
            'audience' => 'public',
            'enabled' => $row->enabled,
            'shipped' => false,
            'overridden' => true,
        ];
    }

    /**
     * The stored rows by slug. Without a database (yet) the shipped articles still show.
     *
     * @return array<string, HelpArticle>
     */
    private function rows(): array
    {
        try {
            $rows = HelpArticle::query()->get();
        } catch (Throwable) {
            return [];
        }

        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[$row->slug] = $row;
        }

        return $bySlug;
    }
}
