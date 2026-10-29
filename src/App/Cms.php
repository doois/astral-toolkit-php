<?php

declare(strict_types=1);

namespace App;

use Astral\AstralException;
use Astral\Client;

/**
 * Everything the templates read from the CMS goes through here: one client, one
 * cached answer per thing, tagged the way the webhook purges. Field shapes follow
 * astral.model.json.
 *
 * The answers live on disk (var/cache/api) with a short TTL (ASTRAL_API_TTL,
 * 60 s). When the TTL is over and the CMS fails (down, slow, 5xx, a revoked key),
 * the last good answer is served for up to ASTRAL_API_STALE seconds (7 days), so a
 * CMS outage never takes the site down. Within one request every answer is also
 * kept in memory.
 */
final class Cms
{
    public const POST_TYPE = 'post';
    public const CATEGORY = 'category';
    public const PAGE_SIZE = 9;

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        public readonly Client $client,
        private readonly FileCache $cache,
        private readonly int $ttl = 60,
        private readonly int $staleFor = 604800,
    ) {
    }

    public static function fromEnv(FileCache $cache): self
    {
        $client = new Client([
            'url' => Env::url(),
            'project' => Env::project(),
            'token' => Env::token() ?: null,
            'retries' => Env::int('ASTRAL_RETRIES', 2),
            'timeout' => (float) Env::int('ASTRAL_TIMEOUT', 10),
        ]);
        return new self($client, $cache, Env::int('ASTRAL_API_TTL', 60), Env::int('ASTRAL_API_STALE', 604800));
    }

    public function cache(): FileCache
    {
        return $this->cache;
    }

    /**
     * The cached answer of `loader`. A 404 from the API becomes `notFound` (and is
     * cached like any answer); any other failure falls back to the last good answer,
     * or is thrown when there is none.
     *
     * @param list<string> $tags
     */
    public function memo(string $key, array $tags, callable $loader, mixed $notFound = null, bool $fallbackOnNotFound = true): mixed
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        $stored = $this->cache->meta($key);
        $hit = null;
        if ($stored !== null) {
            $body = @file_get_contents($stored['file']);
            $decoded = $body === false ? null : json_decode($body, true);
            if (is_array($decoded) && array_key_exists('v', $decoded)) {
                $hit = $decoded['v'];
                if (time() - (int) $stored['storedAt'] < $this->ttl) {
                    return $this->memo[$key] = $hit;
                }
            } else {
                $stored = null;
            }
        }
        try {
            $value = $loader();
        } catch (AstralException $error) {
            if ($fallbackOnNotFound && Client::isNotFound($error)) {
                $value = $notFound;
            } elseif ($stored !== null && time() - (int) $stored['storedAt'] < $this->staleFor) {
                error_log('[astral] CMS failed (' . $error->getMessage() . '), serving the cached answer of ' . $key);
                return $this->memo[$key] = $hit;
            } else {
                throw $error;
            }
        }
        $this->cache->put($key, (string) json_encode(['v' => $value], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), [], $tags);
        return $this->memo[$key] = $value;
    }

    /** Forgets what this request read (after a purge, before a warm). */
    public function forget(): void
    {
        $this->memo = [];
    }

    // ── What the site reads ─────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return $this->memo('schema', ['schema'], fn () => $this->client->schema(), null, false);
    }

    /** @return list<array{from: string, to: string, status: int}> */
    public function redirects(): array
    {
        try {
            return $this->memo('redirects', ['redirects', 'settings'], fn () => $this->client->redirects(), []);
        } catch (AstralException) {
            return [];
        }
    }

    /**
     * Settings, or a bare set with the project slug as name while the single is not published.
     *
     * @return array<string, mixed>
     */
    public function settings(string $locale): array
    {
        $fallback = ['id' => 'single', 'type' => 'settings', 'locale' => $locale, 'meta' => ['url' => null, 'publishedAt' => null, 'updatedAt' => gmdate('Y-m-d\TH:i:s.000\Z', 0)], 'siteName' => Env::project(), 'logo' => null, 'description' => null, 'social' => null, 'footerText' => null, 'contactEmail' => null];
        return $this->memo("settings:{$locale}", ['type:settings', 'settings'], fn () => $this->client->single('settings', ['locale' => $locale]), $fallback);
    }

    /** @return array{apiId: string, name: string, items: list<array<string, mixed>>} */
    public function menu(string $apiId, string $locale): array
    {
        return $this->memo("menu:{$apiId}:{$locale}", ['menus', "menu:{$apiId}"], fn () => $this->client->menu($apiId, $locale), ['apiId' => $apiId, 'name' => $apiId, 'items' => []]);
    }

    /** The site's Organization and WebSite graph, or null. @return array<string, mixed>|null */
    public function siteJsonLd(): ?array
    {
        try {
            return $this->memo('jsonld', ['settings'], fn () => $this->client->jsonLd(), null);
        } catch (AstralException) {
            return null;
        }
    }

    /** @return list<array<string, mixed>> */
    public function terms(string $taxonomy, string $locale): array
    {
        if ($taxonomy === '') {
            return [];
        }
        return $this->memo("terms:{$taxonomy}:{$locale}", ["taxonomy:{$taxonomy}"], fn () => $this->client->terms($taxonomy, $locale), []);
    }

    /** @return array<string, mixed>|null */
    public function term(string $taxonomy, string $ref, string $locale): ?array
    {
        return $this->memo("term:{$taxonomy}:{$locale}:{$ref}", ["taxonomy:{$taxonomy}"], fn () => $this->client->term($taxonomy, $ref, $locale), null);
    }

    /** One entry by slug or id, or null when it does not exist (a 404), with relations two levels deep. @return array<string, mixed>|null */
    public function entry(string $type, string $ref, string $locale): ?array
    {
        return $this->memo("entry:{$type}:{$locale}:{$ref}", ["type:{$type}"], fn () => $this->client->get($type, $ref, ['locale' => $locale, 'populate' => 2]), null);
    }

    /** @return array<string, mixed>|null */
    public function single(string $type, string $locale): ?array
    {
        return $this->memo("single:{$type}:{$locale}", ["type:{$type}"], fn () => $this->client->single($type, ['locale' => $locale, 'populate' => 2]), null);
    }

    /**
     * One page of posts, newest first, optionally inside a category (slug in any locale or id).
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function posts(string $locale, int $page = 1, ?string $category = null, int $pageSize = self::PAGE_SIZE): array
    {
        $empty = ['data' => [], 'meta' => ['page' => 1, 'pageSize' => $pageSize, 'offset' => 0, 'total' => 0, 'pageCount' => 0]];
        $options = ['locale' => $locale, 'page' => $page, 'pageSize' => $pageSize, 'sort' => 'date:desc', 'populate' => 1];
        if ($category !== null && $category !== '') {
            $options['filter'] = [self::CATEGORY => $category];
        }
        return $this->memo('posts:' . $locale . ':' . ($category ?? '') . ":{$page}:{$pageSize}", ['type:' . self::POST_TYPE], fn () => $this->client->list(self::POST_TYPE, $options), $empty);
    }

    /** The CMS sitemap.xml, or null when the project has no site address (then it is built here). */
    public function sitemapXml(): ?string
    {
        return $this->memo('sitemap.xml', ['sitemap'], function (): ?string {
            try {
                return $this->client->sitemapXml();
            } catch (\Astral\AstralHttpException $error) {
                if ($error->errorCode === 'site_url_required') {
                    return null;
                }
                throw $error;
            }
        }, null, false);
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function sitemap(): array
    {
        return $this->memo('sitemap', ['sitemap'], fn () => $this->client->sitemap(), ['data' => [], 'meta' => []]);
    }
}
