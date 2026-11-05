<?php

declare(strict_types=1);

namespace App;

/**
 * The site's Content-Security-Policy. Same policy as the other toolkits
 * (cms/docs/TOOLKITS.md); Response adds it to every HTML answer, cached ones
 * included.
 *
 * Images come from the CMS, on ASTRAL_URL or on its media host. ASTRAL_MEDIA_URL
 * names that host; without it the site cannot know it (nothing in the API says),
 * so img-src takes any https: origin instead of breaking images. JSON-LD is a data
 * block, which script-src does not block; style-src keeps 'unsafe-inline' for the
 * inlined stylesheet and the images' dominant-color style attribute.
 */
final class Csp
{
    public static function policy(): string
    {
        $cms = self::origin(Env::url());
        $media = self::origin(Env::mediaUrl());
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            self::directive('img-src', ["'self'", 'data:', $cms, $media !== '' ? $media : 'https:']),
            "font-src 'self'",
            self::directive('connect-src', ["'self'", $cms]),
            "form-action 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }

    /** @param list<string> $sources */
    private static function directive(string $name, array $sources): string
    {
        return $name . ' ' . implode(' ', array_unique(array_filter($sources, static fn (string $s): bool => $s !== '')));
    }

    /** "https://cms.example.com:8443"; empty for anything that is not an http(s) URL. */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return '';
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
