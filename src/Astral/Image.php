<?php

declare(strict_types=1);

namespace Astral;

/**
 * <img> attributes from a media value, a port of toolkits/client/src/image.ts, so
 * every toolkit renders images the same way: the CMS srcset (WebP at the project's
 * widths), intrinsic width/height (no layout shift), lazy by default, eager and
 * high priority for the first image.
 */
final class Image
{
    /** Raster images get a srcset; SVGs and GIFs are served as they are. */
    public static function isRaster(array $media): bool
    {
        $mime = (string) ($media['mime'] ?? '');
        return str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml' && ($mime !== 'image/gif' || !empty($media['optimized']));
    }

    /** The CMS URL of one sized WebP variant of an image (`?w=640&f=webp`). */
    public static function variantUrl(array $media, int $width): string
    {
        $url = (string) $media['url'];
        $hash = '';
        if (($at = strpos($url, '#')) !== false) {
            $hash = substr($url, $at);
            $url = substr($url, 0, $at);
        }
        [$base, $query] = array_pad(explode('?', $url, 2), 2, '');
        parse_str($query, $params);
        $params['w'] = (string) $width;
        $params['f'] = 'webp';
        return $base . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC1738) . $hash;
    }

    /**
     * Options: `sizes` (default "100vw"), `priority` (eager, fetchpriority="high",
     * decoding="sync"), `alt` (overrides the asset's alt; "" marks a decorative image),
     * `width` (request this width for `src`), `placeholder` (paint the dominant
     * color; default only for JPEGs, which have no transparency).
     *
     * The attributes come in a stable order: src, alt, loading, decoding, width,
     * height, srcset, sizes, fetchpriority, style.
     *
     * @param array{sizes?: string, priority?: bool, alt?: string, width?: int, placeholder?: bool} $options
     * @return array<string, string|int>
     */
    public static function attrs(array $media, array $options = []): array
    {
        $raster = self::isRaster($media);
        $priority = !empty($options['priority']);
        $width = $media['optimized']['width'] ?? $media['width'] ?? null;
        $height = $media['optimized']['height'] ?? $media['height'] ?? null;
        $attrs = [
            'src' => $raster && !empty($options['width']) ? self::variantUrl($media, (int) $options['width']) : (string) $media['url'],
            'alt' => array_key_exists('alt', $options) ? (string) $options['alt'] : (string) ($media['alt'] ?? ''),
            'loading' => $priority ? 'eager' : 'lazy',
            'decoding' => $priority ? 'sync' : 'async',
        ];
        if ($width && $height) {
            $attrs['width'] = (int) $width;
            $attrs['height'] = (int) $height;
        }
        if ($raster && !empty($media['srcset'])) {
            $attrs['srcset'] = (string) $media['srcset'];
            $attrs['sizes'] = $options['sizes'] ?? '100vw';
        }
        if ($priority) {
            $attrs['fetchpriority'] = 'high';
        }
        // A PNG, WebP or GIF may be transparent: a dark box behind a logo is worse than no placeholder.
        if (!empty($media['dominantColor']) && ($options['placeholder'] ?? (($media['mime'] ?? '') === 'image/jpeg'))) {
            $attrs['style'] = 'background-color:' . $media['dominantColor'];
        }
        return $attrs;
    }

    /** The `sizes` value of an image that fills its column up to a maximum width. */
    public static function sizesUpTo(int $maxWidth): string
    {
        return "(min-width: {$maxWidth}px) {$maxWidth}px, 100vw";
    }
}
