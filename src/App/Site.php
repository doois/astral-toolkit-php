<?php

declare(strict_types=1);

namespace App;

use Astral\Render;
use Astral\Urls;

/**
 * What one request knows about the site: the schema (cached), the locale URL
 * format, the route table and the public base URL.
 *
 * The locale format comes from /schema (`localeUrls`, `localeDomains`) when the
 * CMS delivers it; ASTRAL_LOCALE_URLS and ASTRAL_LOCALE_DOMAINS only fill in for
 * a CMS that does not yet; "prefix" is the last fallback, as in the Astro toolkit.
 * In the "domain" format the request's host picks its locale's routes; in "none"
 * every locale has the same paths, so one install serves the locales of
 * ASTRAL_BUILD_LOCALES (the first one by default).
 */
final class Site
{
    /** @var list<array<string, mixed>> */
    public readonly array $types;
    /** @var list<array<string, mixed>> */
    public readonly array $taxonomies;

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $format
     */
    public function __construct(
        public readonly array $schema,
        public readonly array $format,
        public readonly Routes $routes,
        public readonly string $siteUrl,
        public readonly Assets $assets,
        public readonly string $locale,
    ) {
        $this->types = $schema['types'] ?? [];
        $this->taxonomies = $schema['taxonomies'] ?? [];
    }

    public static function load(Cms $cms, Request $request, Assets $assets): self
    {
        $schema = $cms->schema();
        $format = self::formatOf($schema);
        $hostLocale = Urls::localeOfPath('/', $format, $request->host)['locale'];
        $only = array_values(array_filter(array_map('trim', explode(',', Env::get('ASTRAL_BUILD_LOCALES'))), static fn (string $l): bool => in_array($l, $format['locales'], true)));
        $locales = match (true) {
            (bool) $only => $only,
            $format['mode'] === 'domain' && self::hostIsDomain($format, $request->host) => [$hostLocale],
            default => $format['locales'],
        };
        if ($format['mode'] === 'domain' && self::hostIsDomain($format, $request->host)) {
            // The host's locale first; the others stay reachable where their paths differ.
            $locales = array_values(array_unique(array_merge([$hostLocale], $locales)));
        }
        $siteUrl = Env::siteUrl();
        if ($format['mode'] === 'domain' && isset($format['domains'][$hostLocale]) && self::hostIsDomain($format, $request->host)) {
            $siteUrl = rtrim((string) $format['domains'][$hostLocale], '/');
        }
        return new self($schema, $format, new Routes($schema, $format, $locales), $siteUrl !== '' ? $siteUrl : $request->origin(), $assets, $hostLocale);
    }

    /** @param array<string, mixed> $schema @return array<string, mixed> */
    public static function formatOf(array $schema): array
    {
        $domains = [];
        foreach (array_filter(array_map('trim', explode(',', Env::get('ASTRAL_LOCALE_DOMAINS')))) as $pair) {
            [$locale, $url] = array_pad(explode('=', $pair, 2), 2, '');
            if ($locale !== '' && $url !== '') {
                $domains[trim($locale)] = rtrim(trim($url), '/');
            }
        }
        return Urls::localeFormat($schema, [
            'mode' => $schema['localeUrls'] ?? (Env::get('ASTRAL_LOCALE_URLS') ?: null),
            'domains' => $schema['localeDomains'] ?? ($domains ?: null),
        ]);
    }

    /** The hosts of the "domain" format (the page cache keys pages by host there). @return list<string> */
    public static function domainHosts(array $format): array
    {
        if ($format['mode'] !== 'domain') {
            return [];
        }
        $hosts = [];
        foreach ($format['domains'] as $base) {
            $hosts[] = strtolower((string) preg_replace('#[:/].*$#', '', (string) preg_replace('#^https?://#', '', (string) $base)));
        }
        return array_values(array_unique($hosts));
    }

    private static function hostIsDomain(array $format, string $host): bool
    {
        $bare = strtolower((string) preg_replace('/:\d+$/', '', $host));
        return in_array($bare, self::domainHosts($format), true);
    }

    /** @return array<string, mixed>|null */
    public function type(string $apiId): ?array
    {
        return Render::type($this->schema, $apiId);
    }

    public function mode(string $apiId): string
    {
        return Render::mode($this->schema, $apiId);
    }

    /** The first taxonomy with an archive URL that classifies a collection. */
    public function taxonomyOf(string $apiId): string
    {
        foreach ($this->taxonomies as $taxonomy) {
            if (!empty($taxonomy['urlPattern']) && in_array($apiId, $taxonomy['types'] ?? [], true)) {
                return (string) $taxonomy['apiId'];
            }
        }
        return '';
    }
}
