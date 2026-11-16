<?php

declare(strict_types=1);

namespace Pterodactyl\Services\Seo;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Enum\LegalDocument;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Share\ServerPage;
use Pterodactyl\Services\Help\HelpArticles;
use Pterodactyl\Services\Legal\LegalSettings;
use Pterodactyl\Services\Status\StatusPageSettings;
use Throwable;

/**
 * robots.txt and /sitemap.xml: the public pages search engines may list. Every address comes
 * from the configured panel address, never the request's Host header.
 *
 * nginx serves robots.txt straight from public/, so the file is written there (with the sitemap
 * line, which needs the full address) by `p:monoforge:robots`, hourly and after an update.
 */
final readonly class SeoFiles
{
    public const string SITEMAP_CACHE_KEY = 'monoforge:seo:sitemap';

    public const int SITEMAP_CACHE_SECONDS = 3600;

    /** Most public server pages listed; a sitemap may hold 50 000 addresses. */
    public const int MAX_SERVER_PAGES = 10_000;

    /** The crawl rules, without the sitemap line. public/robots.txt in the repository is exactly this. */
    public const string ROBOTS_RULES = <<<'TXT'
User-agent: *
# Only the public pages (and what they need to render) may be crawled; the panel stays out of search.
Allow: /$
Allow: /assets/
Allow: /favicons/
Allow: /locales/
Allow: /api/public/
# Public server pages; an owner can still ask for theirs not to be listed (noindex).
Allow: /s/
# The server discovery page that lists them.
Allow: /servers$
# The help center, the status page and the legal pages.
Allow: /help$
Allow: /help/
Allow: /status$
Allow: /legal/
Allow: /sitemap.xml$
Disallow: /

TXT;

    public function __construct(private CacheRepository $cache, private HelpArticles $help) {}

    /** robots.txt, pointing at the sitemap of the configured panel address. */
    public function robots(): string
    {
        return self::ROBOTS_RULES.'Sitemap: '.$this->root()."/sitemap.xml\n";
    }

    /**
     * Writes robots.txt into public/ when it differs. Never throws: a read-only folder only
     * leaves the shipped file (the same rules without the sitemap line) in place.
     */
    public function writeRobots(?string $path = null): bool
    {
        $path ??= public_path('robots.txt');
        $content = $this->robots();

        try {
            if (is_file($path) && file_get_contents($path) === $content) {
                return true;
            }

            return file_put_contents($path, $content, LOCK_EX) !== false;
        } catch (Throwable $throwable) {
            Log::warning('MonoForge: robots.txt could not be written: '.$throwable->getMessage());

            return false;
        }
    }

    /** The sitemap XML, built at most once an hour. */
    public function sitemap(): string
    {
        return $this->cache->remember(self::SITEMAP_CACHE_KEY, self::SITEMAP_CACHE_SECONDS, fn (): string => $this->buildSitemap());
    }

    public function forget(): void
    {
        $this->cache->forget(self::SITEMAP_CACHE_KEY);
    }

    /**
     * Every listed address with its last change, if known.
     *
     * @return list<array{loc: string, lastmod: string|null}>
     */
    public function entries(): array
    {
        $root = $this->root();
        $entries = [['loc' => $root.'/', 'lastmod' => null]];

        if (StatusPageSettings::enabled()) {
            $entries[] = ['loc' => $root.'/status', 'lastmod' => null];
        }

        $entries[] = ['loc' => $root.'/servers', 'lastmod' => null];
        $entries[] = ['loc' => $root.'/help', 'lastmod' => null];
        foreach ($this->help->published('public') as $article) {
            $entries[] = ['loc' => $root.'/help/'.$article['slug'], 'lastmod' => null];
        }

        foreach (LegalDocument::cases() as $document) {
            // A document kept on another site redirects there; it is not this panel's page.
            if (LegalSettings::externalUrl($document) === '') {
                $entries[] = ['loc' => $root.$document->path(), 'lastmod' => LegalSettings::updatedAt($document)->toDateString()];
            }
        }

        ServerPage::query()
            ->where('published', true)
            ->where('noindex', false)
            // Like PublicServerPages::find: never a suspended server or one that is not installed.
            ->whereHas('server', fn (Builder $server): Builder => $server->where(fn (Builder $status): Builder => $status
                ->whereNull('status')
                ->orWhereNotIn('status', [Server::STATUS_INSTALLING, Server::STATUS_INSTALL_FAILED, Server::STATUS_SUSPENDED])))
            ->orderBy('id')
            ->limit(self::MAX_SERVER_PAGES)
            ->get(['id', 'slug', 'updated_at'])
            ->each(function (ServerPage $page) use (&$entries): void {
                $entries[] = ['loc' => $page->url(), 'lastmod' => $page->updated_at?->toDateString()];
            });

        return $entries;
    }

    private function buildSitemap(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($this->entries() as $entry) {
            $xml .= '  <url><loc>'.htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>';
            if ($entry['lastmod'] !== null) {
                $xml .= '<lastmod>'.$entry['lastmod'].'</lastmod>';
            }

            $xml .= "</url>\n";
        }

        return $xml.'</urlset>'."\n";
    }

    private function root(): string
    {
        return mb_rtrim(config()->string('app.url'), '/');
    }
}
