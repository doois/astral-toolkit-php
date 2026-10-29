<?php

declare(strict_types=1);

namespace App;

/**
 * Static assets under /assets with versioned names: `assets/fonts/inter-variable.woff2`
 * is linked as `/assets/fonts/inter-variable.<10 hex of its md5>.woff2`, which .htaccess
 * (and the built-in server branch of index.php) maps back to the real file and serves
 * with a one-year immutable cache. A changed file gets a new name, so no stale copy.
 *
 * The stylesheet is the Astro toolkit's CSS, file for file (assets/css). It is small,
 * so it is inlined in every page: the @imports of index.css are resolved, comments and
 * whitespace dropped, and the font URLs pointed at their versioned names. The result
 * is kept in var/cache/assets, keyed by the files' modification times.
 */
final class Assets
{
    public const HASHED = '#^/assets/(.+)\.([0-9a-f]{10})\.(css|js|woff2|svg|png|jpg|jpeg|webp|avif|ico)$#';

    /** @var array<string, string> */
    private static array $urls = [];

    public function __construct(private readonly string $root, private readonly string $cacheDir)
    {
    }

    /** The versioned URL of a file under assets/ ("fonts/inter-variable.woff2"). */
    public function url(string $path): string
    {
        if (isset(self::$urls[$path])) {
            return self::$urls[$path];
        }
        $file = $this->root . '/assets/' . $path;
        if (!is_file($file)) {
            return self::$urls[$path] = '/assets/' . $path;
        }
        $hash = substr(md5_file($file) ?: '', 0, 10);
        $dot = strrpos($path, '.');
        return self::$urls[$path] = '/assets/' . substr($path, 0, (int) $dot) . '.' . $hash . substr($path, (int) $dot);
    }

    /** The CSS to inline in <style>. */
    public function inlineCss(): string
    {
        $files = $this->cssFiles($this->root . '/assets/css/index.css');
        $stamp = '';
        foreach ($files as $file) {
            $stamp .= $file . ':' . (int) @filemtime($file) . ';';
        }
        $cached = $this->cacheDir . '/styles-' . substr(sha1($stamp), 0, 12) . '.css';
        $css = @file_get_contents($cached);
        if ($css !== false) {
            return $css;
        }
        $css = '';
        foreach ($files as $file) {
            $css .= preg_replace('/@import\s+[^;]+;/', '', (string) file_get_contents($file)) . "\n";
        }
        $css = self::minify($css);
        $css = (string) preg_replace_callback('#url\("/fonts/([^"]+)"\)#', fn (array $m): string => 'url("' . $this->url('fonts/' . $m[1]) . '")', $css);
        if (is_dir($this->cacheDir) || @mkdir($this->cacheDir, 0775, true)) {
            @file_put_contents($cached, $css, LOCK_EX);
        }
        return $css;
    }

    /** index.css and the files it imports, in order. @return list<string> */
    private function cssFiles(string $entry): array
    {
        $out = [];
        $dir = dirname($entry);
        foreach ($this->imports((string) @file_get_contents($entry)) as $import) {
            $out[] = $dir . '/' . $import;
        }
        $out[] = $entry;
        return $out;
    }

    /** @return list<string> */
    private function imports(string $css): array
    {
        preg_match_all('/@import\s+(?:url\()?["\']\.?\/?([^"\']+)["\'][^;]*;/', $css, $m);
        return $m[1];
    }

    /** Drops comments and needless whitespace; never touches strings or selectors' meaning. */
    public static function minify(string $css): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $css = (string) preg_replace('/\s+/', ' ', $css);
        $css = (string) preg_replace('/\s*([{};,>])\s*/', '$1', $css);
        $css = (string) preg_replace('/:\s+/', ':', $css);
        $css = str_replace(';}', '}', $css);
        return trim($css);
    }

    /** The content type of a static file by extension. */
    public static function mime(string $file): string
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=utf-8',
            'js', 'mjs' => 'text/javascript; charset=utf-8',
            'json' => 'application/json',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'ico' => 'image/x-icon',
            'txt' => 'text/plain; charset=utf-8',
            'xml' => 'application/xml; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
            default => 'application/octet-stream',
        };
    }
}
