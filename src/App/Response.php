<?php

declare(strict_types=1);

namespace App;

/**
 * An HTTP answer. The body is a string, or a file (`bodyFile`, streamed with
 * readfile, for cached pages). `send()` writes it out, honoring HEAD.
 */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
        public ?string $bodyFile = null,
    ) {
    }

    /** Work to run after the answer reached the client (the warm after a full clear). */
    public ?\Closure $after = null;

    /** @param array<string, string> $headers */
    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, ['content-type' => 'text/html; charset=utf-8'] + $headers, $body);
    }

    /** @param array<string, string> $headers */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self($status, ['content-type' => 'application/json'] + $headers, (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function redirect(string $to, int $status = 301): self
    {
        return new self($status, ['location' => $to, 'cache-control' => 'public, max-age=0, s-maxage=3600']);
    }

    /** @param array<string, string> $headers */
    public static function text(string $body, string $type, int $status = 200, array $headers = []): self
    {
        return new self($status, ['content-type' => $type] + $headers, $body);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = $value;
        return $this;
    }

    /** The body as a string (reads the file of a cached page). */
    public function content(): string
    {
        return $this->bodyFile !== null ? (string) @file_get_contents($this->bodyFile) : $this->body;
    }

    /**
     * The headers as they go out. Every HTML document carries the Content-Security-Policy,
     * added here so a page cached on disk gets the current policy, and so does the 304
     * that revalidates a copy the browser kept (304 headers update the stored ones).
     *
     * @return array<string, string>
     */
    public function finalHeaders(): array
    {
        $html = str_starts_with($this->headers['content-type'] ?? '', 'text/html');
        return $html || $this->status === 304 ? $this->headers + ['content-security-policy' => Csp::policy()] : $this->headers;
    }

    public function send(string $method = 'GET'): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->finalHeaders() as $name => $value) {
                header(self::canonicalName($name) . ': ' . $value);
            }
            if ($this->bodyFile !== null && !isset($this->headers['content-length'])) {
                $size = @filesize($this->bodyFile);
                if ($size !== false) {
                    header('Content-Length: ' . $size);
                }
            } elseif ($this->bodyFile === null && !isset($this->headers['content-length']) && $this->status !== 304) {
                header('Content-Length: ' . strlen($this->body));
            }
        }
        if ($method === 'HEAD' || $this->status === 304 || $this->status === 204) {
            return;
        }
        if ($this->bodyFile !== null) {
            readfile($this->bodyFile);
        } else {
            echo $this->body;
        }
    }

    private static function canonicalName(string $name): string
    {
        return $name === 'etag' ? 'ETag' : implode('-', array_map('ucfirst', explode('-', $name)));
    }
}
