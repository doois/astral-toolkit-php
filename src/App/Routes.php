<?php

declare(strict_types=1);

namespace App;

use Astral\Render;
use Astral\Urls;

/**
 * The site's route table, built from the CMS schema on every request (from the
 * cached /schema answer, so it costs nothing): one route per type, locale and
 * kind, in the project's locale URL format. The same table the Astro toolkit
 * injects at build time (src/lib/routes.ts there).
 *
 * A route is `['pattern' => '/en/blog/[slug]', 'kind' => 'entry'|'listing'|'term',
 * 'type' => 'post', 'locale' => 'en', 'mode' => 'static'|'revalidate'|'dynamic',
 * 'taxonomy' => ?string, 'paged' => bool]`.
 */
final class Routes
{
    private const RANK = ['static' => 0, 'revalidate' => 1, 'dynamic' => 2];

    /** @var list<array<string, mixed>> */
    public readonly array $routes;
    /** @var list<array<string, mixed>> */
    public readonly array $dropped;
    /** @var list<array{route: array<string, mixed>, regex: string, score: string}> */
    private array $compiled;

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $format
     * @param list<string>|null $locales only these locales (default: all of the format)
     */
    public function __construct(public readonly array $schema, public readonly array $format, ?array $locales = null)
    {
        [$this->routes, $this->dropped] = self::build($schema, $format, $locales ?? $format['locales']);
        $compiled = [];
        foreach ($this->routes as $route) {
            $compiled[] = ['route' => $route, 'regex' => self::regex($route['pattern']), 'score' => self::score($route['pattern'])];
        }
        // Static segments win over parameters, segment by segment ("/blog" before "/[slug]").
        usort($compiled, static fn (array $a, array $b): int => strcmp($b['score'], $a['score']));
        $this->compiled = $compiled;
    }

    /** The most demanding of several modes (a listing of static and dynamic types is dynamic). */
    public static function mostDynamic(array $modes): string
    {
        $top = 'static';
        foreach ($modes as $mode) {
            if ((self::RANK[$mode] ?? 0) > self::RANK[$top]) {
                $top = $mode;
            }
        }
        return $top;
    }

    /**
     * Every route the site serves. Patterns that repeat (the "domain" and "none" formats
     * give every locale the same paths) keep the first locale only; in the "domain" mode
     * the request's host picks the locale first (see Site).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    public static function build(array $schema, array $format, array $locales): array
    {
        $routes = [];
        $dropped = [];
        $seen = [];
        $add = static function (array $route) use (&$routes, &$dropped, &$seen): void {
            if (isset($seen[$route['pattern']])) {
                $dropped[] = $route;
            } else {
                $seen[$route['pattern']] = true;
                $routes[] = $route;
            }
        };
        $routed = Render::routedTypes($schema);
        foreach ($locales as $locale) {
            $segment = I18n::t($locale, 'pageSegment');
            foreach ($routed as $type) {
                $mode = Render::mode($schema, $type);
                $add(['pattern' => Urls::routePattern($type['urlPattern'], $locale, $format), 'kind' => 'entry', 'type' => $type['apiId'], 'locale' => $locale, 'mode' => $mode, 'taxonomy' => null, 'paged' => false]);
                if (($type['kind'] ?? '') === 'COLLECTION' && Urls::listingPath($type['urlPattern']) !== '/') {
                    $base = self::pathOnly(Urls::listingUrl($type['urlPattern'], $locale, $format));
                    $add(['pattern' => $base, 'kind' => 'listing', 'type' => $type['apiId'], 'locale' => $locale, 'mode' => $mode, 'taxonomy' => null, 'paged' => false]);
                    $add(['pattern' => self::join($base, "{$segment}/[page]"), 'kind' => 'listing', 'type' => $type['apiId'], 'locale' => $locale, 'mode' => $mode, 'taxonomy' => null, 'paged' => true]);
                }
            }
            foreach ($schema['taxonomies'] ?? [] as $taxonomy) {
                if (empty($taxonomy['urlPattern'])) {
                    continue;
                }
                $types = array_values(array_filter($routed, static fn (array $t): bool => in_array($t['apiId'], $taxonomy['types'] ?? [], true) && ($t['kind'] ?? '') === 'COLLECTION'));
                if (!$types) {
                    continue;
                }
                $mode = self::mostDynamic(array_map(static fn (array $t): string => Render::mode($schema, $t), $types));
                $pattern = Urls::routePattern($taxonomy['urlPattern'], $locale, $format);
                $add(['pattern' => $pattern, 'kind' => 'term', 'type' => $types[0]['apiId'], 'locale' => $locale, 'mode' => $mode, 'taxonomy' => $taxonomy['apiId'], 'paged' => false]);
                $add(['pattern' => self::join($pattern, "{$segment}/[page]"), 'kind' => 'term', 'type' => $types[0]['apiId'], 'locale' => $locale, 'mode' => $mode, 'taxonomy' => $taxonomy['apiId'], 'paged' => true]);
            }
        }
        return [$routes, $dropped];
    }

    /**
     * The route serving a path, with its parameters (URL-decoded), or null.
     *
     * @return array{route: array<string, mixed>, params: array<string, string>}|null
     */
    public function match(string $path): ?array
    {
        foreach ($this->compiled as $item) {
            if (preg_match($item['regex'], $path, $m)) {
                $params = [];
                foreach ($m as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = rawurldecode($value);
                    }
                }
                return ['route' => $item['route'], 'params' => $params];
            }
        }
        return null;
    }

    /** The URL of page N of a listing or term route ("/blog" for page 1, "/blog/pagina/2" after). */
    public static function pagedUrl(string $base, int $page, string $locale): string
    {
        return $page <= 1 ? $base : self::join($base, I18n::t($locale, 'pageSegment') . "/{$page}");
    }

    private static function join(string $base, string $segment): string
    {
        return $base === '/' ? "/{$segment}" : "{$base}/{$segment}";
    }

    private static function pathOnly(string $url): string
    {
        $path = (string) preg_replace('#^https?://[^/]+#', '', $url);
        return $path !== '' ? $path : '/';
    }

    private static function regex(string $pattern): string
    {
        if ($pattern === '/') {
            return '#^/$#';
        }
        $parts = [];
        foreach (explode('/', ltrim($pattern, '/')) as $segment) {
            $parts[] = preg_match('/^\[(\w+)\]$/', $segment, $m) ? '(?P<' . $m[1] . '>[^/]+)' : preg_quote($segment, '#');
        }
        return '#^/' . implode('/', $parts) . '$#';
    }

    /** One digit per segment: 1 for a literal, 0 for a parameter; longer literal prefixes sort first. */
    private static function score(string $pattern): string
    {
        $score = '';
        foreach (explode('/', ltrim($pattern, '/')) as $segment) {
            $score .= preg_match('/^\[\w+\]$/', $segment) ? '0' : '1';
        }
        return $score;
    }
}
