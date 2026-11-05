<?php

declare(strict_types=1);

namespace App;

use Astral\AstralException;
use Astral\AstralHttpException;
use Astral\Image;
use Astral\Urls;

/**
 * The site: one Request in, one Response out. index.php builds the request from
 * the globals; bin/warm.php builds them by hand to fill the cache.
 *
 * Order of work for a page:
 * 1. the site's own endpoints (/astral/revalidate, /astral/posts, /preview,
 *    robots.txt, sitemap.xml);
 * 2. the HTML cache: a hit is served from disk (or as a 304) without touching the
 *    CMS or a template;
 * 3. the CMS redirects;
 * 4. the route table from /schema, then the entry, listing or term archive;
 * 5. the page is rendered and, for static and revalidate types, stored.
 */
final class Kernel
{
    public const STATIC_CACHE_CONTROL = 'public, max-age=0, must-revalidate';
    public const DYNAMIC_CACHE_CONTROL = 'public, max-age=0, s-maxage=60, stale-while-revalidate=600';

    public readonly Cms $cms;
    public readonly PageCache $pages;
    public readonly Assets $assets;
    private ?Site $site = null;

    public function __construct(public readonly string $root, public readonly string $var, ?Cms $cms = null)
    {
        $this->cms = $cms ?? Cms::fromEnv(new FileCache("{$var}/cache/api"));
        $this->pages = new PageCache(new FileCache("{$var}/cache/html"), $this->knownHosts());
        $this->assets = new Assets($root, "{$var}/cache/assets");
    }

    public static function boot(string $root): self
    {
        $var = Env::get('ASTRAL_VAR_DIR') ?: "{$root}/var";
        self::protect($var);
        return new self($root, rtrim($var, '/\\'));
    }

    /** A fresh kernel on the same folders, with nothing remembered from this request. */
    public function fresh(): self
    {
        $this->cms->forget();
        $copy = new self($this->root, $this->var, $this->cms);
        return $copy;
    }

    public function handle(Request $request): Response
    {
        $path = (string) preg_replace('#/{2,}#', '/', $request->path);
        if ($path !== '/' && str_ends_with($path, '/')) {
            $to = rtrim($path, '/') ?: '/';
            return Response::redirect($to . ($request->query ? '?' . http_build_query($request->query) : ''));
        }

        switch ($path) {
            case '/astral/revalidate':
                if ($request->method !== 'POST') {
                    return Response::json(['ok' => false, 'error' => 'method_not_allowed'], 405, ['allow' => 'POST']);
                }
                return (new Revalidator($this))->handle($request);
            case '/astral/posts':
                return $this->guard(fn () => $this->livePosts($request));
            case '/preview':
                return $this->guard(fn () => $this->preview($request));
            case '/robots.txt':
                return $this->robots($request);
            case '/sitemap.xml':
                return $this->guard(fn () => $this->sitemap($request));
        }

        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return Response::text('Method not allowed', 'text/plain; charset=utf-8', 405, ['allow' => 'GET, HEAD']);
        }

        $hit = $this->pages->get($path, $request->host);
        if ($hit !== null) {
            $headers = (array) $hit['headers'] + ['x-astral-cache' => 'hit'];
            $headers['x-astral-cache'] = 'hit';
            return PageCache::answer($request, (int) $hit['status'], $headers, (string) $hit['etag'], $hit['file'], $hit['gzFile'] ?? null);
        }

        return $this->guard(fn () => $this->render($request, $path));
    }

    /** The site of this request (schema, locale format, routes). */
    public function site(Request $request): Site
    {
        if ($this->site === null) {
            $this->site = Site::load($this->cms, $request, $this->assets);
            $hosts = Site::domainHosts($this->site->format);
            if ($hosts !== $this->knownHosts()) {
                $this->cms->cache()->put('_hosts', (string) json_encode($hosts));
            }
            $this->pages->setHosts($hosts);
        }
        return $this->site;
    }

    // ── Pages ───────────────────────────────────────────────────────────

    private function render(Request $request, string $path): Response
    {
        foreach ($this->cms->redirects() as $redirect) {
            $from = rtrim((string) ($redirect['from'] ?? ''), '/') ?: '/';
            if (str_starts_with((string) ($redirect['from'] ?? ''), '/') && $from === $path && $redirect['to'] !== $redirect['from']) {
                $status = in_array((int) $redirect['status'], [300, 301, 302, 303, 307, 308], true) ? (int) $redirect['status'] : 301;
                return Response::redirect((string) $redirect['to'], $status);
            }
        }

        $site = $this->site($request);
        $match = $site->routes->match($path);
        if ($match === null) {
            return $this->notFound($request, $path);
        }
        ['route' => $route, 'params' => $params] = $match;
        $tags = ['type:' . $route['type']];

        $outcome = match ($route['kind']) {
            'entry' => $this->resolveEntry($site, $route, $params, $path, $tags),
            'listing' => $this->resolveListing($site, $route, $params),
            'term' => $this->resolveTerm($site, $route, $params, $tags),
        };
        if ($outcome['kind'] === 'redirect') {
            return Response::redirect($outcome['to']);
        }
        if ($outcome['kind'] === 'notFound') {
            return $this->notFound($request, $path);
        }

        $view = new View($site, $this->cms, $path, "{$this->root}/templates");
        $html = $view->render($outcome['template'], $outcome['props']);
        return $this->store($request, $path, $html, $route['mode'], $tags);
    }

    /**
     * Stores a rendered page by its render mode and answers it.
     *
     * @param list<string> $tags
     */
    private function store(Request $request, string $path, string $html, string $mode, array $tags): Response
    {
        $tags = array_values(array_unique($tags));
        $headers = ['content-type' => 'text/html; charset=utf-8', 'x-astral-render' => $mode];
        if ($mode === 'dynamic') {
            $headers['cache-control'] = self::DYNAMIC_CACHE_CONTROL;
            return PageCache::answer($request, 200, $headers, PageCache::etagOf($html), null, null, $html);
        }
        $headers['cache-control'] = Env::get('ASTRAL_HTML_CACHE_CONTROL', self::STATIC_CACHE_CONTROL);
        if ($mode === 'revalidate') {
            $headers['cache-tag'] = implode(',', $tags);
        }
        $etag = $this->pages->put($path, $request->host, $html, 200, $headers, $mode, $tags);
        return PageCache::answer($request, 200, $headers + ['x-astral-cache' => 'miss'], $etag, null, null, $html);
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, string> $params
     * @param list<string> $tags
     * @return array<string, mixed>
     */
    private function resolveEntry(Site $site, array $route, array $params, string $path, array &$tags): array
    {
        $type = $site->type($route['type']) ?? [];
        $ref = $params['slug'] ?? $params['id'] ?? '';
        $entry = ($type['kind'] ?? '') === 'SINGLE' ? $this->cms->single($route['type'], $route['locale']) : ($ref !== '' ? $this->cms->entry($route['type'], $ref, $route['locale']) : null);
        if (!$entry) {
            return ['kind' => 'notFound'];
        }
        // The CMS decides the URL (the page with slug "home" is "/"; a slug of another locale
        // has its own URL): any other address of the same entry points there.
        $own = Urls::pathOf($entry);
        if ($own !== null && rawurldecode($own) !== rawurldecode($path)) {
            return ['kind' => 'redirect', 'to' => (string) $entry['meta']['url']];
        }
        $tags[] = 'entry:' . $entry['id'];
        return ['kind' => 'ok', 'template' => 'pages/entry', 'props' => ['entry' => $entry, 'locale' => $route['locale'], 'preview' => false, 'banner' => '']];
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function resolveListing(Site $site, array $route, array $params): array
    {
        $type = $site->type($route['type']) ?? [];
        $locale = $route['locale'];
        $base = Urls::listingUrl($type['urlPattern'] ?? null, $locale, $site->format);
        $page = $route['paged'] ? self::pageNumber($params['page'] ?? '') : 1;
        if ($page === null) {
            return ['kind' => 'redirect', 'to' => $base];
        }
        $list = $this->cms->posts($locale, $page, null, Cms::PAGE_SIZE);
        $terms = $this->cms->terms($site->taxonomyOf($route['type']), $locale);
        if ($page > max((int) $list['meta']['pageCount'], 1)) {
            return ['kind' => 'notFound'];
        }
        $alternates = array_map(static fn (string $l): array => ['locale' => $l, 'url' => Routes::pagedUrl(Urls::listingUrl($type['urlPattern'] ?? null, $l, $site->format), $page, $l)], $site->format['locales']);
        return ['kind' => 'ok', 'template' => 'pages/listing', 'props' => ['locale' => $locale, 'title' => I18n::t($locale, 'blog'), 'description' => null, 'posts' => $list['data'], 'meta' => $list['meta'], 'base' => $base, 'allUrl' => $base, 'terms' => $terms, 'current' => null, 'alternates' => $alternates]];
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, string> $params
     * @param list<string> $tags
     * @return array<string, mixed>
     */
    private function resolveTerm(Site $site, array $route, array $params, array &$tags): array
    {
        $type = $site->type($route['type']) ?? [];
        $locale = $route['locale'];
        $taxonomy = (string) $route['taxonomy'];
        $slug = $params['slug'] ?? '';
        $term = $slug !== '' ? $this->cms->term($taxonomy, $slug, $locale) : null;
        if (!$term) {
            return ['kind' => 'notFound'];
        }
        $allUrl = Urls::listingUrl($type['urlPattern'] ?? null, $locale, $site->format);
        $base = (string) ($term['url'] ?? $allUrl);
        $page = $route['paged'] ? self::pageNumber($params['page'] ?? '') : 1;
        if ($page === null) {
            return ['kind' => 'redirect', 'to' => $base];
        }
        // The term's own slug is the canonical one in this locale: a slug of another locale redirects.
        if ($term['slug'] !== $slug) {
            return ['kind' => 'redirect', 'to' => Routes::pagedUrl($base, $page, $locale)];
        }
        $list = $this->cms->posts($locale, $page, (string) $term['id'], Cms::PAGE_SIZE);
        $terms = $this->cms->terms($taxonomy, $locale);
        if ($page > max((int) $list['meta']['pageCount'], 1)) {
            return ['kind' => 'notFound'];
        }
        $tags[] = "taxonomy:{$taxonomy}";
        $alternates = array_map(static fn (array $a): array => ['locale' => $a['locale'], 'url' => !empty($a['url']) ? Routes::pagedUrl($a['url'], $page, $a['locale']) : null], $term['meta']['alternates'] ?? []);
        return ['kind' => 'ok', 'template' => 'pages/listing', 'props' => ['locale' => $locale, 'title' => $term['name'], 'description' => $term['description'] ?? null, 'posts' => $list['data'], 'meta' => $list['meta'], 'base' => $base, 'allUrl' => $allUrl, 'terms' => $terms, 'current' => $term, 'alternates' => $alternates]];
    }

    /** Page 2 and up of a paged route; null for anything else (page 1 lives at the base URL). */
    private static function pageNumber(string $raw): ?int
    {
        return preg_match('/^[1-9]\d{0,5}$/', $raw) && (int) $raw >= 2 ? (int) $raw : null;
    }

    private function notFound(Request $request, string $path): Response
    {
        $site = $this->site($request);
        $locale = Urls::localeOfPath($path, $site->format, $request->host)['locale'];
        $view = new View($site, $this->cms, $path, "{$this->root}/templates");
        $html = $view->render('pages/not-found', ['locale' => $locale]);
        return Response::html($html, 404, ['cache-control' => 'no-cache', 'x-astral-render' => 'dynamic']);
    }

    // ── The site's own endpoints ────────────────────────────────────────

    /** Draft preview: never cached, never indexed, says it is a draft. The CMS admin may frame it, and its live edit agent rides on the banner (README). */
    private function preview(Request $request): Response
    {
        $headers = ['cache-control' => 'private, no-store', 'x-robots-tag' => 'noindex, nofollow', 'content-security-policy' => Csp::policy(true)];
        $token = $request->query['token'] ?? $request->query['preview'] ?? '';
        $entry = null;
        $problem = '';
        if ($token === '') {
            $problem = 'token_missing';
        } else {
            try {
                $entry = $this->cms->client->preview($token);
            } catch (AstralHttpException $error) {
                $problem = $error->errorCode;
            } catch (AstralException) {
                $problem = 'preview_failed';
            }
        }
        if (!$entry) {
            $html = (new View($this->site($request), $this->cms, '/preview', "{$this->root}/templates"))->render('pages/preview-error', ['problem' => $problem]);
            return Response::html($html, 401, $headers);
        }
        $site = $this->site($request);
        $locale = (string) ($entry['locale'] ?? $site->format['defaultLocale']);
        $banner = '<div class="preview-banner" role="status">' . e(I18n::t($locale, 'draftPreview')) . '</div><script src="' . e(Csp::liveEditAgent()) . '" defer></script>';
        $html = (new View($site, $this->cms, '/preview', "{$this->root}/templates"))->render('pages/entry', ['entry' => $entry, 'locale' => $locale, 'preview' => true, 'banner' => $banner]);
        return Response::html($html, 200, $headers);
    }

    /** Data for the live postlist island: the newest posts as ready-to-render cards. The CMS key stays here. */
    private function livePosts(Request $request): Response
    {
        $site = $this->site($request);
        $locale = in_array($request->query['locale'] ?? '', $site->format['locales'], true) ? $request->query['locale'] : $site->format['defaultLocale'];
        $limit = min(max((int) ($request->query['limit'] ?? 3) ?: 3, 1), 12);
        $category = trim($request->query['category'] ?? '') ?: null;
        $list = $this->cms->posts($locale, 1, $category, $limit);
        $cards = [];
        foreach ($list['data'] as $post) {
            $category = $post['category'][0] ?? null;
            $cover = null;
            if (!empty($post['cover'])) {
                $attrs = Image::attrs($post['cover'], ['sizes' => '(min-width: 64em) 24rem, (min-width: 40em) 50vw, 100vw', 'alt' => '']);
                $cover = ['src' => $attrs['src']];
                if (isset($attrs['srcset'])) {
                    $cover += ['srcset' => $attrs['srcset'], 'sizes' => $attrs['sizes']];
                }
                if (isset($attrs['width'])) {
                    $cover += ['width' => $attrs['width'], 'height' => $attrs['height']];
                }
                if (isset($attrs['style'])) {
                    $cover['style'] = $attrs['style'];
                }
            }
            $cards[] = [
                'url' => $post['meta']['url'] ?? null,
                'title' => $post['title'] ?? '',
                'excerpt' => $post['excerpt'] ?? null,
                'date' => $post['date'] ?? null,
                'dateLabel' => I18n::formatDate($post['date'] ?? null, $locale),
                'category' => $category ? ['name' => $category['name'], 'url' => $category['url'] ?? null] : null,
                'cover' => $cover,
            ];
        }
        return Response::json(['data' => $cards], 200, ['cache-control' => self::DYNAMIC_CACHE_CONTROL]);
    }

    private function robots(Request $request): Response
    {
        $siteUrl = Env::siteUrl() ?: $request->origin();
        $body = implode("\n", ['User-agent: *', 'Allow: /', 'Disallow: /preview', 'Disallow: /astral/', '', "Sitemap: {$siteUrl}/sitemap.xml", '']);
        return PageCache::answer($request, 200, ['content-type' => 'text/plain; charset=utf-8', 'cache-control' => 'public, max-age=3600'], PageCache::etagOf($body), null, null, $body);
    }

    /** The sitemap the CMS builds (hreflang, x-default, images); without a site address in the project, assembled here with SITE_URL. */
    private function sitemap(Request $request): Response
    {
        $body = $this->cms->sitemapXml();
        if ($body === null) {
            $siteUrl = Env::siteUrl() ?: $request->origin();
            $xml = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $loc = static fn (string $url): string => preg_match('#^https?://#', $url) ? $url : $siteUrl . $url;
            $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'];
            foreach ($this->cms->sitemap()['data'] as $u) {
                $line = '<url><loc>' . $xml($loc($u['url'])) . '</loc><lastmod>' . $xml((string) $u['lastmod']) . '</lastmod>';
                foreach ($u['alternates'] ?? [] as $a) {
                    $line .= '<xhtml:link rel="alternate" hreflang="' . $xml($a['locale']) . '" href="' . $xml($loc($a['url'])) . '"/>';
                }
                foreach ($u['images'] ?? [] as $i) {
                    $line .= '<image:image><image:loc>' . $xml($i['loc']) . '</image:loc></image:image>';
                }
                $lines[] = $line . '</url>';
            }
            $lines[] = '</urlset>';
            $body = implode("\n", $lines);
        }
        return PageCache::answer($request, 200, ['content-type' => 'application/xml; charset=utf-8', 'cache-control' => self::STATIC_CACHE_CONTROL], PageCache::etagOf($body), null, null, $body);
    }

    // ── Failure ─────────────────────────────────────────────────────────

    /** The CMS unreachable with nothing cached: a 503 that says so, never cached. */
    private function guard(callable $work): Response
    {
        try {
            return $work();
        } catch (AstralException $error) {
            error_log('[astral] ' . $error->getMessage());
            $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Indisponível</title></head>'
                . '<body style="font-family: system-ui, sans-serif; padding: 2rem; max-width: 40rem; margin: auto"><h1>Site temporariamente indisponível</h1><p>Tente de novo em alguns instantes.</p></body></html>';
            return Response::html($html, 503, ['cache-control' => 'no-store', 'retry-after' => '60']);
        }
    }

    /** @return list<string> */
    private function knownHosts(): array
    {
        $raw = $this->cms->cache()->body('_hosts');
        $hosts = $raw === null ? [] : json_decode($raw, true);
        return is_array($hosts) ? array_values($hosts) : [];
    }

    /** var/ never answers over HTTP, even when the host ignores the root .htaccess rules. */
    private static function protect(string $var): void
    {
        if (!is_file("{$var}/.htaccess") && (is_dir($var) || @mkdir($var, 0775, true))) {
            @file_put_contents("{$var}/.htaccess", "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
            @file_put_contents("{$var}/index.html", '');
        }
    }
}
