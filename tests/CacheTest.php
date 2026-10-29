<?php

declare(strict_types=1);

namespace Tests;

use App\FileCache;
use App\PageCache;
use App\Request;
use Astral\AstralNetworkException;

final class CacheTest extends TestCase
{
    public function testFileCacheStoresPurgesByTagAndClears(): void
    {
        $cache = new FileCache($this->tempDir() . '/c');
        $cache->put('a', 'A', ['x' => 1], ['type:post', 'entry:1'], true);
        $cache->put('b', 'B', [], ['type:post']);
        $cache->put('c', 'C', [], ['type:page']);
        $this->assertSame('A', $cache->body('a'));
        $this->assertSame(1, $cache->meta('a')['x']);
        $this->assertSame('A', gzdecode((string) file_get_contents($cache->meta('a')['gzFile'])));
        $this->assertSame(2, $cache->purgeTags(['type:post']));
        $this->assertSame(null, $cache->meta('a'));
        $this->assertSame(null, $cache->body('b'));
        $this->assertSame('C', $cache->body('c'));
        $this->assertSame(0, $cache->purgeTags(['type:post']), 'the index went with the purge');
        $this->assertSame(1, $cache->clear());
        $this->assertSame(0, $cache->count());
        $cache->put('d', 'D');
        $this->assertSame('D', $cache->body('d'), 'writes work right after a clear');
    }

    public function testPageCacheKeysByHostOnlyInTheDomainFormat(): void
    {
        $pages = new PageCache(new FileCache($this->tempDir() . '/html'));
        $pages->put('/blog/a', 'site.test:8080', '<p>a</p>', 200, [], 'revalidate', ['type:post']);
        $this->assertTrue($pages->get('/blog/a', 'other.test') !== null, 'one page per path outside the domain format');
        $this->assertSame(1, $pages->purgePaths(['/blog/a/']));

        $pages->setHosts(['example.com', 'en.example.com']);
        $pages->put('/blog/a', 'example.com', 'pt', 200, [], 'revalidate', []);
        $pages->put('/blog/a', 'en.example.com', 'en', 200, [], 'revalidate', []);
        $this->assertSame('en', (string) file_get_contents($pages->get('/blog/a', 'en.example.com')['file']));
        $this->assertSame(1, $pages->purgePaths(['https://en.example.com/blog/a']), 'a full URL purges its host only');
        $this->assertTrue($pages->get('/blog/a', 'example.com') !== null);
        $this->assertSame(1, $pages->purgePaths(['/blog/a']), 'a bare path purges every host');
    }

    public function testStaticPagesIgnoreTagPurges(): void
    {
        $pages = new PageCache(new FileCache($this->tempDir() . '/html'));
        $pages->put('/', '', 'home', 200, [], 'static', ['type:home']);
        $pages->put('/blog', '', 'blog', 200, [], 'revalidate', ['type:post']);
        $this->assertSame(1, $pages->purgeTags(['type:home', 'type:post']));
        $this->assertTrue($pages->get('/') !== null, 'static pages wait for a full clear');
        $this->assertSame(1, $pages->clear());
    }

    public function testEtagsAnd304(): void
    {
        $etag = PageCache::etagOf('body');
        $request = new Request('GET', '/', [], 'x', 'http', ['if-none-match' => "W/{$etag}", 'accept-encoding' => 'gzip']);
        $this->assertSame(304, PageCache::answer($request, 200, ['cache-control' => 'c'], $etag, null, null, 'body')->status);
        $gzipEtag = '"' . trim($etag, '"') . '-gzip"';
        $this->assertSame(304, PageCache::answer(new Request('GET', '/', [], 'x', 'http', ['if-none-match' => $gzipEtag]), 200, [], $etag, null, null, 'body')->status);
        $fresh = PageCache::answer(new Request('GET', '/', [], 'x', 'http', ['accept-encoding' => 'gzip']), 200, [], $etag, null, null, 'body');
        $this->assertSame(200, $fresh->status);
        $this->assertSame('gzip', $fresh->header('content-encoding'));
        $this->assertSame('body', gzdecode($fresh->body));
    }

    public function testCmsAnswersAreCachedAndServedStaleWhenTheCmsFails(): void
    {
        $dir = $this->tempDir();
        $calls = [];
        $down = false;
        $transport = Fixtures::transport(['/schema' => [200, Fixtures::schema()], '/menus/header' => [200, ['data' => ['apiId' => 'header', 'name' => 'Menu', 'items' => [['label' => 'Blog', 'url' => '/blog']]]]]], $calls, $down);
        $cms = Fixtures::cms($dir, $transport, 0);
        $this->assertSame('Blog', $cms->menu('header', 'pt-BR')['items'][0]['label']);

        // TTL 0: the next request asks again; the CMS is down, the cached answer is served.
        $down = true;
        $again = Fixtures::cms($dir, $transport, 0);
        $this->assertSame('Blog', $again->menu('header', 'pt-BR')['items'][0]['label']);
        $this->assertSame(2, count($calls));

        // Nothing cached and the CMS down: the error goes up (the site answers 503).
        $this->assertThrows(AstralNetworkException::class, fn () => $again->schema());

        // A 404 is an answer: the fallback, cached like any other.
        $down = false;
        $fresh = Fixtures::cms($dir, $transport);
        $this->assertSame(['apiId' => 'footer', 'name' => 'footer', 'items' => []], $fresh->menu('footer', 'pt-BR'));
        $count = count($calls);
        Fixtures::cms($dir, $transport)->menu('footer', 'pt-BR');
        $this->assertSame($count, count($calls), 'served from disk within the TTL');
    }
}
