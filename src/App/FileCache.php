<?php

declare(strict_types=1);

namespace App;

/**
 * A small tagged cache on disk, used twice: CMS responses (var/cache/api) and
 * rendered pages (var/cache/html).
 *
 * Each entry is up to three files named by the sha1 of its key: `<hash>.json`
 * (metadata, written last, so its presence means the entry is complete),
 * `<hash>.body` and optionally `<hash>.gz` (the body gzipped once, served as is).
 * Writes go to a temporary file and are renamed into place, so a reader never
 * sees half an entry. Tags live in `_tags/<sha1(tag)>.idx`, one entry hash per
 * line: purging a tag deletes those entries and the index.
 *
 * Works on any host PHP can write to (shared hosting included): no locks held
 * while serving, no database.
 */
final class FileCache
{
    public function __construct(private readonly string $dir)
    {
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public static function hash(string $key): string
    {
        return sha1($key);
    }

    /**
     * The entry's metadata (with `file` and, when stored, `gzFile`), or null on a miss.
     *
     * @return array<string, mixed>|null
     */
    public function meta(string $key): ?array
    {
        $base = $this->base(self::hash($key));
        $raw = @file_get_contents($base . '.json');
        if ($raw === false) {
            return null;
        }
        $meta = json_decode($raw, true);
        if (!is_array($meta) || !is_file($base . '.body')) {
            return null;
        }
        $meta['file'] = $base . '.body';
        if (is_file($base . '.gz')) {
            $meta['gzFile'] = $base . '.gz';
        }
        return $meta;
    }

    /** The entry's body, or null on a miss. */
    public function body(string $key): ?string
    {
        $meta = $this->meta($key);
        if ($meta === null) {
            return null;
        }
        $body = @file_get_contents($meta['file']);
        return $body === false ? null : $body;
    }

    /**
     * Stores an entry. `meta` is any JSON-encodable data; `storedAt`, `key` and `tags`
     * are added. With `gzip`, a gzipped copy of the body is written too.
     *
     * @param array<string, mixed> $meta
     * @param list<string> $tags
     */
    public function put(string $key, string $body, array $meta = [], array $tags = [], bool $gzip = false): bool
    {
        $hash = self::hash($key);
        $base = $this->base($hash);
        if (!$this->ensureDir(dirname($base))) {
            return false;
        }
        $meta = ['key' => $key, 'storedAt' => time(), 'tags' => array_values(array_unique($tags))] + $meta;
        $ok = $this->write($base . '.body', $body);
        if ($ok && $gzip && function_exists('gzencode')) {
            $ok = $this->write($base . '.gz', (string) gzencode($body, 6));
        } elseif ($ok) {
            @unlink($base . '.gz');
        }
        $ok = $ok && $this->write($base . '.json', (string) json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($ok && $tags) {
            $this->index($hash, $tags);
        }
        return $ok;
    }

    public function delete(string $key): bool
    {
        return $this->deleteHash(self::hash($key));
    }

    /**
     * Deletes every entry carrying one of the tags, and the tags' indexes.
     *
     * @param list<string> $tags
     */
    public function purgeTags(array $tags): int
    {
        $n = 0;
        foreach (array_unique($tags) as $tag) {
            $file = $this->tagFile($tag);
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }
            @unlink($file);
            foreach (array_unique($lines) as $hash) {
                if (preg_match('/^[0-9a-f]{40}$/', $hash) && $this->deleteHash($hash)) {
                    $n++;
                }
            }
        }
        return $n;
    }

    /** Deletes everything; returns how many entries went. */
    public function clear(): int
    {
        if (!is_dir($this->dir)) {
            return 0;
        }
        // Move the folder aside first: new writes start in a fresh folder at once, and a
        // reader never mixes old and new entries.
        $trash = $this->dir . '.trash-' . bin2hex(random_bytes(4));
        $target = @rename($this->dir, $trash) ? $trash : $this->dir;
        $n = self::removeTree($target);
        // Leftovers of an earlier clear that was interrupted.
        foreach (glob($this->dir . '.trash-*', GLOB_ONLYDIR) ?: [] as $old) {
            self::removeTree($old);
        }
        return $n;
    }

    /** How many entries are stored (walks the folder: for tests and the CLI). */
    public function count(): int
    {
        if (!is_dir($this->dir)) {
            return 0;
        }
        $n = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.json')) {
                $n++;
            }
        }
        return $n;
    }

    // ── Internals ───────────────────────────────────────────────────────

    private function base(string $hash): string
    {
        return $this->dir . '/' . substr($hash, 0, 2) . '/' . $hash;
    }

    private function tagFile(string $tag): string
    {
        return $this->dir . '/_tags/' . sha1($tag) . '.idx';
    }

    private function deleteHash(string $hash): bool
    {
        $base = $this->base($hash);
        // The metadata goes first: without it the entry is a miss, whatever is left.
        $existed = @unlink($base . '.json');
        @unlink($base . '.body');
        @unlink($base . '.gz');
        return $existed;
    }

    /** @param list<string> $tags */
    private function index(string $hash, array $tags): void
    {
        if (!$this->ensureDir($this->dir . '/_tags')) {
            return;
        }
        foreach (array_unique($tags) as $tag) {
            @file_put_contents($this->tagFile($tag), $hash . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function write(string $file, string $content): bool
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $content) === false) {
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    private function ensureDir(string $dir): bool
    {
        return is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir);
    }

    /** Removes a folder and returns how many entries (metadata files) it held. */
    public static function removeTree(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                if (str_ends_with($path, '.json') && !str_contains($path, '_tags')) {
                    $n++;
                }
                @unlink($path);
            }
        }
        @rmdir($dir);
        return $n;
    }
}
