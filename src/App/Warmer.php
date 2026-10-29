<?php

declare(strict_types=1);

namespace App;

/**
 * Fills the HTML cache: reads the CMS sitemap (every entry, listing and term
 * archive, in every locale) and renders each URL in this process, following the
 * listings' rel="next" links so every page of the blog is there too. Run by
 * `php bin/warm.php` after a deploy and by the webhook after a full clear.
 *
 * One warm at a time (a lock in var/); a warm asked for while one runs is
 * remembered and runs right after, so the last change is never left out.
 */
final class Warmer
{
    public function __construct(private readonly Kernel $kernel, private readonly ?BuildStatus $status = null)
    {
    }

    /**
     * @param callable(string): void|null $log
     * @return array{ok: bool, state: string, pages: int, failed: list<string>, seconds: float}
     */
    public function run(bool $clear = false, ?callable $log = null, int $limit = 5000): array
    {
        $log ??= static function (string $line): void {
        };
        $dir = "{$this->kernel->var}/cache";
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $lock = @fopen("{$dir}/warm.lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            @touch("{$dir}/warm.pending");
            $log('another warm is running: this one runs after it');
            return ['ok' => true, 'state' => 'queued', 'pages' => 0, 'failed' => [], 'seconds' => 0.0];
        }
        try {
            do {
                @unlink("{$dir}/warm.pending");
                $result = $this->once($clear, $log, $limit);
                $clear = false;
            } while (is_file("{$dir}/warm.pending"));
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param callable(string): void $log
     * @return array{ok: bool, state: string, pages: int, failed: list<string>, seconds: float}
     */
    private function once(bool $clear, callable $log, int $limit): array
    {
        $started = microtime(true);
        $this->status?->report('building');
        if ($clear) {
            $html = $this->kernel->pages->clear();
            $api = $this->kernel->cms->cache()->clear();
            $log("cleared {$html} pages and {$api} CMS answers");
        }
        $failed = [];
        $pages = 0;
        try {
            $kernels = [];
            $queue = [];
            $seen = [];
            $this->kernel->cms->forget();
            foreach ($this->kernel->cms->sitemap()['data'] as $url) {
                $queue[] = (string) ($url['loc'] ?? $url['url']);
            }
            while ($queue && $pages < $limit) {
                $url = array_shift($queue);
                $parts = parse_url($url);
                $path = (string) ($parts['path'] ?? '/');
                $path = rtrim($path, '/') ?: '/';
                $host = isset($parts['host']) ? $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') : 'localhost';
                $key = "{$host}{$path}";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $kernel = $kernels[$host] ??= $this->kernel->fresh();
                $response = $kernel->handle(new Request('GET', $path, [], $host, (string) ($parts['scheme'] ?? 'http')));
                $pages++;
                if ($response->status !== 200) {
                    $failed[] = "{$path} ({$response->status})";
                    $log("  {$response->status} {$path}");
                    continue;
                }
                $log("  {$response->status} {$path} " . ($response->header('x-astral-cache') ?? $response->header('x-astral-render') ?? ''));
                if (preg_match_all('#<a href="([^"]+)" rel="next"#', $response->content(), $m)) {
                    foreach ($m[1] as $next) {
                        $queue[] = html_entity_decode($next);
                    }
                }
            }
        } catch (\Throwable $error) {
            $seconds = round(microtime(true) - $started, 1);
            $this->status?->report('failed', 'Cache não recriado: ' . $error->getMessage());
            $log('failed: ' . $error->getMessage());
            return ['ok' => false, 'state' => 'failed', 'pages' => $pages, 'failed' => $failed, 'seconds' => $seconds];
        }
        $seconds = round(microtime(true) - $started, 1);
        $ok = !$failed;
        $this->status?->report($ok ? 'success' : 'failed', $ok ? "Cache recriado: {$pages} páginas em {$seconds}s" : count($failed) . " de {$pages} páginas falharam: " . implode(', ', array_slice($failed, 0, 5)));
        return ['ok' => $ok, 'state' => 'done', 'pages' => $pages, 'failed' => $failed, 'seconds' => $seconds];
    }
}
