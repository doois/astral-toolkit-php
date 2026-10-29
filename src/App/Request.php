<?php

declare(strict_types=1);

namespace App;

/** The parts of an HTTP request the site reads. Built from the globals or by hand (warm, tests). */
final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers lower-case names
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly string $host = 'localhost',
        public readonly string $scheme = 'http',
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly string $ip = '127.0.0.1',
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($headers['x-forwarded-proto'] ?? '') === 'https';
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        return new self(
            $method,
            $path !== '' ? $path : '/',
            array_map('strval', array_filter($_GET, 'is_scalar')),
            (string) ($headers['host'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'),
            $https ? 'https' : 'http',
            $headers,
            $method === 'POST' ? (string) file_get_contents('php://input') : '',
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function origin(): string
    {
        return "{$this->scheme}://{$this->host}";
    }

    /** The host without a port, lower case. */
    public function hostname(): string
    {
        return strtolower((string) preg_replace('/:\d+$/', '', $this->host));
    }

    public function isLocal(): bool
    {
        return in_array($this->ip, ['127.0.0.1', '::1'], true) || PHP_SAPI === 'cli';
    }
}
