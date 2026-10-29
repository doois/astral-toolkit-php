<?php

declare(strict_types=1);

namespace Astral;

/**
 * Public URLs, a port of toolkits/client/src/urls.ts. The API already delivers
 * URLs in the project's format (meta.url, menus, terms, sitemap); these helpers
 * exist for what a site must build itself: route patterns, listing pages, locale
 * detection and free internal links.
 *
 * A locale format is `['locales' => [...], 'defaultLocale' => 'pt-BR', 'mode' => 'prefix', 'domains' => ['en' => 'https://en.example.com']]`.
 */
final class Urls
{
    public const MODES = ['prefix', 'prefix-all', 'domain', 'none'];

    /**
     * The project's locale URL format from its schema; `override` wins (an env var,
     * say) when the server does not deliver it yet.
     *
     * @param array<string, mixed> $schema
     * @param array{mode?: string|null, domains?: array<string, string>|null} $override
     * @return array{locales: list<string>, defaultLocale: string, mode: string, domains: array<string, string>}
     */
    public static function localeFormat(array $schema, array $override = []): array
    {
        $raw = $override['mode'] ?? $schema['localeUrls'] ?? 'prefix';
        $mode = in_array($raw, self::MODES, true) ? $raw : 'prefix';
        return [
            'locales' => array_values($schema['locales'] ?? []),
            'defaultLocale' => (string) ($schema['defaultLocale'] ?? ''),
            'mode' => $mode,
            'domains' => $override['domains'] ?? $schema['localeDomains'] ?? [],
        ];
    }

    /** Puts a locale-free path where the format says; `placed` means the path already carries its locale. */
    private static function place(string $path, string $locale, array $format, bool $placed): string
    {
        $base = $format['mode'] === 'domain' ? ($format['domains'][$locale] ?? null) : null;
        if ($base) {
            return $base . ($path === '/' ? '' : $path);
        }
        if ($placed || $format['mode'] === 'none' || (($format['mode'] === 'prefix' || $format['mode'] === 'domain') && $locale === $format['defaultLocale'])) {
            return $path;
        }
        return '/' . $locale . ($path === '/' ? '' : $path);
    }

    /**
     * Fills "/blog/{slug}" and places the locale. `{locale}` inside the pattern is never prefixed again.
     *
     * @param array{slug: string, id?: string|null, locale: string} $values
     */
    public static function fillPattern(string $pattern, array $values, array $format): string
    {
        $path = preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => match ($m[1]) {
            'slug' => $values['slug'],
            'locale' => $values['locale'],
            'id' => $values['id'] ?? $values['slug'],
            default => '',
        }, $pattern);
        return self::place($path, $values['locale'], $format, str_contains($pattern, '{locale}'));
    }

    /** The locale-free path a collection lists at: "/blog/{slug}" lists at "/blog". */
    public static function listingPath(?string $urlPattern): string
    {
        $path = $urlPattern === null ? '' : (string) preg_replace('#/?\{[^}]+\}.*$#', '', $urlPattern);
        return $path !== '' ? $path : '/';
    }

    /** "/blog/{slug}" lists at "/blog"; in another locale wherever the format puts it ("/en/blog"). */
    public static function listingUrl(?string $urlPattern, string $locale, array $format): string
    {
        return self::place(self::listingPath($urlPattern), $locale, $format, $urlPattern !== null && str_contains($urlPattern, '{locale}'));
    }

    /** A free internal path ("/contato") in a locale; external links stay as they are. */
    public static function localizePath(string $path, string $locale, array $format): string
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $path;
        }
        return self::place($path, $locale, $format, $path === "/{$locale}" || str_starts_with($path, "/{$locale}/"));
    }

    /**
     * The path part of a public URL (domain URLs lose their origin); "/" for the root.
     *
     * @param string|array<string, mixed>|null $urlOrEntry a URL or an entry (its meta.url)
     */
    public static function pathOf(string|array|null $urlOrEntry): ?string
    {
        $url = is_array($urlOrEntry) ? ($urlOrEntry['meta']['url'] ?? null) : $urlOrEntry;
        if ($url === null || $url === '') {
            return null;
        }
        $path = rtrim((string) preg_replace('#^https?://[^/]+#', '', $url), '/');
        return $path !== '' ? $path : '/';
    }

    /**
     * The locale a request path (and host, for the `domain` mode) belongs to, with the
     * locale-free rest of the path. Unknown prefixes fall to the default locale.
     *
     * @return array{locale: string, path: string}
     */
    public static function localeOfPath(string $pathname, array $format, ?string $host = null): array
    {
        $clean = rtrim($pathname, '/');
        $clean = $clean !== '' ? $clean : '/';
        if ($format['mode'] === 'domain' && $host) {
            $bare = (string) preg_replace('/:\d+$/', '', $host);
            foreach ($format['domains'] as $locale => $base) {
                $domainHost = (string) preg_replace('#/.*$#', '', (string) preg_replace('#^https?://#', '', $base));
                if ($domainHost === $bare) {
                    return ['locale' => (string) $locale, 'path' => $clean];
                }
            }
        }
        if ($format['mode'] !== 'none') {
            $first = explode('/', substr($clean, 1))[0];
            if (in_array($first, $format['locales'], true)) {
                $rest = substr($clean, strlen($first) + 1);
                return ['locale' => $first, 'path' => $rest !== '' ? $rest : '/'];
            }
        }
        return ['locale' => $format['defaultLocale'], 'path' => $clean];
    }

    /**
     * The route pattern of a type in one locale, in the `[param]` notation most routers
     * share: "/blog/{slug}" in "en" under the prefix format is "/en/blog/[slug]".
     */
    public static function routePattern(string $urlPattern, string $locale, array $format): string
    {
        $url = (string) preg_replace('#^https?://[^/]+#', '', self::fillPattern($urlPattern, ['slug' => '{slug}', 'id' => '{id}', 'locale' => $locale], $format));
        $pattern = (string) preg_replace('/\{(\w+)\}/', '[$1]', $url);
        $pattern = $pattern !== '' ? $pattern : '/';
        $pattern = rtrim($pattern, '/');
        return $pattern !== '' ? $pattern : '/';
    }
}
