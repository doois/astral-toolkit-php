<?php

declare(strict_types=1);

namespace Astral;

/**
 * The delivery API client (cms/docs/API.md), a port of toolkits/client/src/client.ts.
 * No dependencies: curl when the extension is loaded, PHP streams otherwise.
 * Every answer is the decoded JSON as associative arrays.
 *
 * Errors are typed (AstralNotFoundException, AstralAuthException, AstralHttpException,
 * AstralNetworkException) and network errors and 5xx answers are retried with a
 * doubling delay.
 */
final class Client
{
    public readonly string $baseUrl;
    public readonly string $apiUrl;

    private int $retries;
    private int $retryDelayMs;
    private ?string $locale;
    /** @var array<string, string> */
    private array $headers;
    /** @var callable(string, array<string, string>): array{status: int, body: string} */
    private $transport;
    /** @var callable(int): void */
    private $sleep;

    /**
     * @param array{
     *   url: string, project: string, token?: string|null, locale?: string|null,
     *   retries?: int, retryDelayMs?: int, timeout?: float, headers?: array<string, string>,
     *   transport?: callable(string, array<string, string>): array{status: int, body: string},
     *   sleep?: callable(int): void,
     * } $options
     */
    public function __construct(array $options)
    {
        $this->baseUrl = rtrim($options['url'], '/');
        $this->apiUrl = $this->baseUrl . '/api/' . rawurlencode($options['project']);
        $this->retries = $options['retries'] ?? 2;
        $this->retryDelayMs = $options['retryDelayMs'] ?? 300;
        $this->locale = $options['locale'] ?? null;
        $this->headers = ['accept' => 'application/json'] + ($options['headers'] ?? []);
        if (!empty($options['token'])) {
            $this->headers['authorization'] = 'Bearer ' . $options['token'];
        }
        $this->transport = $options['transport'] ?? self::defaultTransport((float) ($options['timeout'] ?? 15));
        $this->sleep = $options['sleep'] ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    // ── Query helpers ───────────────────────────────────────────────────

    /**
     * Flattens `['price' => ['lte' => 50], 'category' => 'x', 'tags' => ['a', 'b']]`
     * into `filter[...]` query parameters.
     *
     * @param array<string, mixed>|null $filter
     * @return array<string, string>
     */
    public static function filterParams(?array $filter): array
    {
        $out = [];
        if (!$filter) {
            return $out;
        }
        $scalar = static fn (mixed $v): string => is_array($v) ? implode(',', array_map(self::stringOf(...), $v)) : self::stringOf($v);
        foreach ($filter as $field => $value) {
            if (is_array($value) && !array_is_list($value)) {
                foreach ($value as $op => $v) {
                    if ($v !== null) {
                        $out["filter[{$field}][{$op}]"] = $scalar($v);
                    }
                }
            } elseif (is_array($value)) {
                $out["filter[{$field}][in]"] = implode(',', array_map(self::stringOf(...), $value));
            } else {
                $out["filter[{$field}]"] = $scalar($value);
            }
        }
        return $out;
    }

    /**
     * Query parameters of a list call. Options: locale, sort (string or list), page,
     * pageSize, limit, offset, fields (list), populate (relation depth), preview, richtext, filter.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function listParams(array $options): array
    {
        $query = [];
        if (array_key_exists('locale', $options)) {
            $query['locale'] = $options['locale'];
        }
        $query += [
            'sort' => isset($options['sort']) && is_array($options['sort']) ? implode(',', $options['sort']) : ($options['sort'] ?? null),
            'page' => $options['page'] ?? null,
            'pageSize' => $options['pageSize'] ?? null,
            'limit' => $options['limit'] ?? null,
            'offset' => $options['offset'] ?? null,
            'fields' => isset($options['fields']) ? implode(',', $options['fields']) : null,
            'depth' => $options['populate'] ?? null,
            'preview' => !empty($options['preview']) ? 'true' : null,
            'richtext' => $options['richtext'] ?? null,
        ];
        return $query + self::filterParams($options['filter'] ?? null);
    }

    /**
     * The full URL of an API path with its query (null and empty values are left out).
     * A `locale` key set to null means "no locale at all" (the preview endpoint);
     * a missing key means "the client's default".
     *
     * @param array<string, mixed> $query
     */
    public function url(string $path, array $query = []): string
    {
        $params = [];
        foreach ($query as $key => $value) {
            if ($value !== null && $value !== '') {
                $params[$key] = self::stringOf($value);
            }
        }
        if ($this->locale !== null && !array_key_exists('locale', $query)) {
            $params['locale'] = $this->locale;
        }
        return $this->apiUrl . $path . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC1738) : '');
    }

    /**
     * A GET with retries; returns the parsed JSON body, or the text for non-JSON answers.
     *
     * @param array<string, mixed> $query
     */
    public function request(string $path, array $query = [], bool $auth = true): mixed
    {
        $url = $this->url($path, $query);
        $headers = $this->headers;
        if (!$auth) {
            unset($headers['authorization']);
        }
        $attempt = 0;
        for (;;) {
            try {
                $response = ($this->transport)($url, $headers);
            } catch (AstralNetworkException $error) {
                if ($attempt >= $this->retries) {
                    throw $error;
                }
                ($this->sleep)($this->retryDelayMs * 2 ** $attempt++);
                continue;
            }
            $json = self::parseJson($response['body']);
            $status = $response['status'];
            if ($status >= 200 && $status < 300) {
                return $json ?? $response['body'];
            }
            if ($status >= 500 && $attempt < $this->retries) {
                ($this->sleep)($this->retryDelayMs * 2 ** $attempt++);
                continue;
            }
            throw AstralHttpException::fromResponse($status, $url, $json ?? $response['body']);
        }
    }

    // ── Delivery API ────────────────────────────────────────────────────

    /**
     * One page of a collection: `['data' => [...entries], 'meta' => [page, pageSize, offset, total, pageCount]]`.
     *
     * @param array<string, mixed> $options
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(string $type, array $options = []): array
    {
        return $this->request('/content/' . rawurlencode($type), self::listParams($options));
    }

    /**
     * Every entry of a collection, paging through the API 100 at a time.
     *
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function listAll(string $type, array $options = []): array
    {
        unset($options['page'], $options['limit'], $options['offset']);
        $out = [];
        for ($page = 1;; $page++) {
            $body = $this->list($type, ['pageSize' => $options['pageSize'] ?? 100, 'page' => $page] + $options);
            array_push($out, ...$body['data']);
            if ($page >= (int) $body['meta']['pageCount']) {
                break;
            }
        }
        return $out;
    }

    /**
     * One entry by slug or document id, with `meta.alternates` per locale.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function get(string $type, string $slugOrId, array $options = []): array
    {
        return $this->request('/content/' . rawurlencode($type) . '/' . rawurlencode($slugOrId), self::listParams($options))['data'];
    }

    /**
     * A single-entry type (settings, home).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function single(string $type, array $options = []): array
    {
        return $this->request('/content/' . rawurlencode($type), self::listParams($options))['data'];
    }

    /** @return array{apiId: string, name: string, items: list<array<string, mixed>>} */
    public function menu(string $apiId, ?string $locale = null): array
    {
        return $this->request('/menus/' . rawurlencode($apiId), self::localeQuery($locale))['data'];
    }

    /** @return list<array<string, mixed>> */
    public function menus(?string $locale = null): array
    {
        return $this->request('/menus', self::localeQuery($locale))['data'];
    }

    /** All terms of a taxonomy, in tree order. @return list<array<string, mixed>> */
    public function terms(string $taxonomy, ?string $locale = null): array
    {
        return $this->request('/taxonomies/' . rawurlencode($taxonomy), self::localeQuery($locale))['data'];
    }

    /** One term by slug (any locale) or id, with its children and alternates. @return array<string, mixed> */
    public function term(string $taxonomy, string $slugOrId, ?string $locale = null): array
    {
        return $this->request('/taxonomies/' . rawurlencode($taxonomy) . '/' . rawurlencode($slugOrId), self::localeQuery($locale))['data'];
    }

    /** Types, fields, components, taxonomies and locales the key sees (plus `render` per type). @return array<string, mixed> */
    public function schema(): array
    {
        return $this->request('/schema');
    }

    /** Everything a sitemap needs (entries, listings, term archives, with alternates and images). @return array<string, mixed> */
    public function sitemap(): array
    {
        return $this->request('/sitemap');
    }

    /** The finished sitemap.xml (needs the project's site address). */
    public function sitemapXml(): string
    {
        return (string) $this->request('/sitemap.xml');
    }

    /** Organization and WebSite structured data for every page's head. @return array<string, mixed> */
    public function jsonLd(): array
    {
        return $this->request('/jsonld')['data'];
    }

    /** Redirects set in the admin. @return list<array{from: string, to: string, status: int}> */
    public function redirects(): array
    {
        return $this->request('/redirects')['data'];
    }

    /** The GA4 configuration the site should install, or null. @return array<string, mixed>|null */
    public function analytics(): ?array
    {
        return $this->request('/analytics')['data'];
    }

    /** The draft behind a preview token (the editor's "Preview" button). No key needed. @return array<string, mixed> */
    public function preview(string $token): array
    {
        if ($token === '') {
            throw new AstralException('preview_token_required');
        }
        return $this->request('/preview', ['token' => $token, 'locale' => null], false)['data'];
    }

    /** Whether an error means "nothing at this address" (render a 404) rather than a broken setup. */
    public static function isNotFound(\Throwable $error): bool
    {
        return $error instanceof AstralHttpException && $error->status === 404;
    }

    // ── Internals ───────────────────────────────────────────────────────

    /** @return array<string, string|null> */
    private static function localeQuery(?string $locale): array
    {
        return $locale === null ? [] : ['locale' => $locale];
    }

    private static function stringOf(mixed $value): string
    {
        return match (true) {
            $value === true => 'true',
            $value === false => 'false',
            default => (string) $value,
        };
    }

    private static function parseJson(string $text): mixed
    {
        if ($text === '') {
            return null;
        }
        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /** @return callable(string, array<string, string>): array{status: int, body: string} */
    public static function defaultTransport(float $timeout = 15.0): callable
    {
        if (function_exists('curl_init')) {
            return static function (string $url, array $headers) use ($timeout): array {
                $ch = curl_init($url);
                $lines = [];
                foreach ($headers as $name => $value) {
                    $lines[] = "{$name}: {$value}";
                }
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => $lines,
                    CURLOPT_CONNECTTIMEOUT => (int) min(5, ceil($timeout)),
                    CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_ENCODING => '',
                ]);
                $body = curl_exec($ch);
                if ($body === false) {
                    $reason = curl_error($ch);
                    curl_close($ch);
                    throw new AstralNetworkException($url, $reason);
                }
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                return ['status' => $status, 'body' => (string) $body];
            };
        }
        return static function (string $url, array $headers) use ($timeout): array {
            $lines = '';
            foreach ($headers as $name => $value) {
                $lines .= "{$name}: {$value}\r\n";
            }
            $context = stream_context_create(['http' => ['method' => 'GET', 'header' => $lines, 'timeout' => $timeout, 'ignore_errors' => true]]);
            $body = @file_get_contents($url, false, $context);
            /** @var list<string> $http_response_header */
            $responseHeaders = $http_response_header ?? [];
            if ($body === false || !$responseHeaders) {
                throw new AstralNetworkException($url, error_get_last()['message'] ?? 'no response');
            }
            $status = 0;
            foreach ($responseHeaders as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                }
            }
            return ['status' => $status, 'body' => $body];
        };
    }
}
