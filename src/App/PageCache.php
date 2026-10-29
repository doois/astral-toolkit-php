<?php

declare(strict_types=1);

namespace App;

/**
 * Rendered HTML on disk (var/cache/html), per render mode:
 *
 * - static: stored without tags; only a full clear (a rebuild event) removes it.
 * - revalidate: stored with its tags (type:post, entry:<id>, taxonomy:<x>); the
 *   webhook deletes the changed paths and every page carrying a changed tag.
 * - dynamic: never stored.
 *
 * Pages are keyed by path, plus the host in the "domain" locale format (the same
 * path is another page on another domain). Query strings never reach the key: no
 * template reads them. Each entry keeps its ETag and a gzipped copy, so a hit is a
 * file read (or a 304) with no CMS call and no template run.
 */
final class PageCache
{
    /** @param list<string> $hosts the hosts of the "domain" format (empty otherwise) */
    public function __construct(private readonly FileCache $files, private array $hosts = [])
    {
    }

    public function files(): FileCache
    {
        return $this->files;
    }

    /** @param list<string> $hosts */
    public function setHosts(array $hosts): void
    {
        $this->hosts = array_values(array_map('strtolower', $hosts));
    }

    public function key(string $path, string $host = ''): string
    {
        $host = strtolower((string) preg_replace('/:\d+$/', '', $host));
        return ($this->hosts && in_array($host, $this->hosts, true) ? $host : '') . $path;
    }

    /** @return array<string, mixed>|null */
    public function get(string $path, string $host = ''): ?array
    {
        return $this->files->meta($this->key($path, $host));
    }

    /**
     * Stores a page and returns its ETag.
     *
     * @param array<string, string> $headers
     * @param list<string> $tags
     */
    public function put(string $path, string $host, string $html, int $status, array $headers, string $mode, array $tags): string
    {
        $etag = self::etagOf($html);
        $this->files->put($this->key($path, $host), $html, ['status' => $status, 'headers' => $headers, 'mode' => $mode, 'etag' => $etag], $mode === 'revalidate' ? $tags : [], function_exists('gzencode'));
        return $etag;
    }

    /**
     * Deletes the pages at these paths. A full URL ("https://en.example.com/blog/x")
     * names one host; a bare path is deleted on every host of the site.
     *
     * @param list<string> $paths
     */
    public function purgePaths(array $paths): int
    {
        $n = 0;
        foreach ($paths as $path) {
            $host = '';
            if (preg_match('#^https?://([^/]+)(.*)$#', $path, $m)) {
                $host = $m[1];
                $path = $m[2];
            }
            $path = rtrim($path, '/');
            $path = $path !== '' ? $path : '/';
            $keys = [$this->key($path, $host)];
            if ($host === '') {
                foreach ($this->hosts as $known) {
                    $keys[] = $known . $path;
                }
            }
            foreach (array_unique($keys) as $key) {
                if ($this->files->delete($key)) {
                    $n++;
                }
            }
        }
        return $n;
    }

    /** @param list<string> $tags */
    public function purgeTags(array $tags): int
    {
        return $this->files->purgeTags($tags);
    }

    public function clear(): int
    {
        return $this->files->clear();
    }

    public static function etagOf(string $body): string
    {
        return '"' . substr(sha1($body), 0, 20) . '"';
    }

    /** Whether If-None-Match names this ETag (the gzip variant counts as the same). */
    public static function notModified(Request $request, string $etag): bool
    {
        $header = $request->header('if-none-match');
        if ($header === '') {
            return false;
        }
        if (trim($header) === '*') {
            return true;
        }
        $bare = trim($etag, '"');
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim((string) preg_replace('#^W/#', '', trim($candidate)), '"');
            if ($candidate === $bare || $candidate === "{$bare}-gzip") {
                return true;
            }
        }
        return false;
    }

    /** Whether the answer may be gzipped by PHP (the client accepts it and nothing else compresses). */
    public static function canGzip(Request $request): bool
    {
        return Env::bool('ASTRAL_GZIP', true)
            && function_exists('gzencode')
            && !filter_var(ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN)
            && str_contains(strtolower($request->header('accept-encoding')), 'gzip');
    }

    /**
     * The answer for a request: a 304, the gzipped copy or the plain body.
     *
     * @param array<string, string> $headers
     */
    public static function answer(Request $request, int $status, array $headers, string $etag, ?string $file, ?string $gzFile, ?string $body = null): Response
    {
        $headers['etag'] = $etag;
        $headers['vary'] = 'Accept-Encoding';
        if ($status === 200 && self::notModified($request, $etag)) {
            return new Response(304, array_intersect_key($headers, array_flip(['etag', 'cache-control', 'vary', 'cache-tag', 'x-astral-cache', 'x-astral-render'])));
        }
        if (self::canGzip($request)) {
            $headers['content-encoding'] = 'gzip';
            $headers['etag'] = '"' . trim($etag, '"') . '-gzip"';
            if ($gzFile !== null) {
                return new Response($status, $headers, '', $gzFile);
            }
            $plain = $body ?? (string) @file_get_contents((string) $file);
            return new Response($status, $headers, (string) gzencode($plain, 6));
        }
        return $body !== null ? new Response($status, $headers, $body) : new Response($status, $headers, '', $file);
    }
}
