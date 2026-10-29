<?php

declare(strict_types=1);

namespace Tests;

use App\Env;
use App\Kernel;
use App\Request;
use App\Routes;
use Astral\Urls;
use Astral\Webhook;

final class RoutingTest extends TestCase
{
    private function routes(string $mode = 'prefix'): Routes
    {
        $schema = Fixtures::schema();
        return new Routes($schema, Urls::localeFormat($schema, ['mode' => $mode]));
    }

    public function testRouteTableFromTheSchema(): void
    {
        $patterns = array_map(static fn (array $r): string => $r['kind'] . ' ' . $r['pattern'] . ' ' . $r['mode'], $this->routes()->routes);
        foreach (['entry /blog/[slug] revalidate', 'listing /blog revalidate', 'listing /blog/pagina/[page] revalidate', 'entry /[slug] static', 'entry / static', 'term /blog/categoria/[slug] revalidate', 'term /blog/categoria/[slug]/pagina/[page] revalidate', 'entry /en/blog/[slug] revalidate', 'listing /en/blog/page/[page] revalidate', 'entry /en static', 'entry /en/[slug] static'] as $expected) {
            $this->assertTrue(in_array($expected, $patterns, true), "route {$expected}");
        }
    }

    public function testMatchPrefersLiteralSegments(): void
    {
        $routes = $this->routes();
        $this->assertSame('listing', $routes->match('/blog')['route']['kind']);
        $this->assertSame(['slug' => 'sobre'], $routes->match('/sobre')['params']);
        $this->assertSame('home', $routes->match('/en')['route']['type'], '/en is the English home, not a page "en"');
        $this->assertSame('en', $routes->match('/en/blog/x')['route']['locale']);
        $this->assertSame(['slug' => 'design', 'page' => '2'], $routes->match('/blog/categoria/design/pagina/2')['params']);
        $this->assertSame(['slug' => 'olá'], $routes->match('/' . rawurlencode('olá'))['params']);
        $this->assertSame(null, $routes->match('/a/b/c/d/e'));
    }

    public function testSameLocalePathsAreDroppedInTheNoneFormat(): void
    {
        $routes = $this->routes('none');
        $this->assertTrue(count($routes->dropped) > 0);
        $this->assertSame('pt-BR', $routes->match('/blog')['route']['locale']);
        $this->assertSame('/blog/pagina/2', Routes::pagedUrl('/blog', 2, 'pt-BR'));
        $this->assertSame('/en/blog', Routes::pagedUrl('/en/blog', 1, 'en'));
    }

    /** The whole request cycle against a fake CMS: routing, render, cache, purge. */
    public function testKernelRendersCachesAndRevalidates(): void
    {
        $dir = $this->tempDir();
        $post = ['id' => 'p1', 'type' => 'post', 'locale' => 'pt-BR', 'slug' => 'ola', 'title' => 'Olá <mundo>', 'excerpt' => 'Resumo', 'body' => '<p>Corpo</p>', 'date' => '2026-10-14', 'meta' => ['url' => '/blog/ola', 'updatedAt' => '2026-10-14T00:00:00Z', 'alternates' => []]];
        $routes = [
            '/schema' => [200, Fixtures::schema()],
            '/redirects' => [200, ['data' => [['from' => '/antigo', 'to' => '/blog', 'status' => 308]]]],
            '/content/post/ola' => [200, ['data' => $post]],
            '/content/post' => [200, ['data' => [$post], 'meta' => ['page' => 1, 'pageSize' => 9, 'offset' => 0, 'total' => 1, 'pageCount' => 1]]],
            '/content/settings' => [200, ['data' => ['siteName' => 'Estúdio Fictício', 'meta' => ['url' => null]]]],
        ];
        $calls = [];
        $kernel = new Kernel(dirname(__DIR__), $dir, Fixtures::cms($dir, Fixtures::transport($routes, $calls)));

        $page = $kernel->handle(new Request('GET', '/blog/ola'));
        $this->assertSame(200, $page->status);
        $this->assertSame('miss', $page->header('x-astral-cache'));
        $this->assertSame('type:post,entry:p1', $page->header('cache-tag'));
        $this->assertContains('<h1>Olá &lt;mundo&gt;</h1>', $page->body, 'text is escaped');
        $this->assertContains('<div class="prose"><p>Corpo</p></div>', $page->body, 'rich text is printed as delivered');
        $this->assertContains('<title>Olá &lt;mundo&gt; · Estúdio Fictício</title>', $page->body);
        $this->assertContains('"@type":"BlogPosting"', $page->body);

        $hit = (new Kernel(dirname(__DIR__), $dir, Fixtures::cms($dir, Fixtures::transport($routes))))->handle(new Request('GET', '/blog/ola'));
        $this->assertSame('hit', $hit->header('x-astral-cache'));
        $this->assertSame($page->body, $hit->content());

        $this->assertSame(404, $kernel->handle(new Request('GET', '/blog/nada'))->status);
        $this->assertSame(301, $kernel->handle(new Request('GET', '/blog/ola/'))->status);
        $redirect = $kernel->handle(new Request('GET', '/antigo'));
        $this->assertSame([308, '/blog'], [$redirect->status, $redirect->header('location')]);
        $this->assertSame(301, $kernel->handle(new Request('GET', '/blog/pagina/1'))->status);
        $this->assertSame(200, $kernel->handle(new Request('GET', '/blog'))->status);

        // A signed post event deletes the post and the listing, nothing else.
        $previous = getenv('ASTRAL_WEBHOOK_SECRET');
        putenv('ASTRAL_WEBHOOK_SECRET=whsec_test_only');
        putenv('ASTRAL_WARM_ON_CLEAR=0');
        try {
            $body = (string) json_encode(['event' => 'entry.published', 'projectId' => 'x', 'sentAt' => 'now', 'data' => ['type' => 'post', 'documentId' => 'p1', 'paths' => ['/blog/ola'], 'tags' => ['type:post', 'entry:p1']]]);
            $s = Webhook::signBody($body, 'whsec_test_only');
            $headers = ['x-cms-timestamp' => $s['timestamp'], 'x-cms-signature' => $s['signature']];
            $this->assertSame(401, $kernel->handle(new Request('POST', '/astral/revalidate', [], 'x', 'http', ['x-cms-timestamp' => $s['timestamp'], 'x-cms-signature' => str_repeat('0', 64)], $body))->status);
            $answer = $kernel->handle(new Request('POST', '/astral/revalidate', [], 'x', 'http', $headers, $body));
            $result = json_decode($answer->body, true);
            $this->assertSame(true, $result['ok']);
            $this->assertSame(false, $result['cleared']);
            $this->assertSame(2, $result['purged'], 'the post (by path, then its tags) and the listing (by tag)');
            $this->assertSame(null, $kernel->pages->get('/blog/ola'));
            $this->assertSame(null, $kernel->pages->get('/blog'));

            // A settings change clears every page (there is no build to run).
            $kernel->handle(new Request('GET', '/blog/ola'));
            $body = '{"event":"settings.updated","data":{"tags":["settings"]}}';
            $s = Webhook::signBody($body, 'whsec_test_only');
            $result = json_decode($kernel->handle(new Request('POST', '/astral/revalidate', [], 'x', 'http', ['x-cms-timestamp' => $s['timestamp'], 'x-cms-signature' => $s['signature']], $body))->body, true);
            $this->assertSame([true, 'disabled'], [$result['cleared'], $result['warm']]);
            $this->assertSame(0, $kernel->pages->files()->count());
        } finally {
            putenv($previous === false ? 'ASTRAL_WEBHOOK_SECRET' : "ASTRAL_WEBHOOK_SECRET={$previous}");
            putenv('ASTRAL_WARM_ON_CLEAR');
        }
        $this->assertSame('', Env::get('ASTRAL_WARM_ON_CLEAR'));
    }
}
