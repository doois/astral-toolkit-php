<?php

declare(strict_types=1);

namespace Tests;

use Astral\AstralAuthException;
use Astral\AstralHttpException;
use Astral\AstralNetworkException;
use Astral\AstralNotFoundException;
use Astral\Client;
use Astral\Image;
use Astral\RichText;

final class ClientTest extends TestCase
{
    /** @param list<array{0: int, 1: string}|\Throwable> $answers */
    private function client(array $answers, array &$seen, int $retries = 2): Client
    {
        return new Client(['url' => 'http://cms.test/', 'project' => 'demo', 'token' => 'cms_test_key', 'retries' => $retries, 'sleep' => static function (int $ms) use (&$seen): void {
            $seen[] = "sleep:{$ms}";
        }, 'transport' => static function (string $url, array $headers) use (&$answers, &$seen): array {
            $seen[] = $url . ' auth=' . (isset($headers['authorization']) ? 'yes' : 'no');
            $next = array_shift($answers);
            if ($next instanceof \Throwable) {
                throw $next;
            }
            return ['status' => $next[0], 'body' => $next[1]];
        }]);
    }

    public function testRetriesNetworkErrorsAnd5xxWithDoublingDelay(): void
    {
        $seen = [];
        $client = $this->client([new AstralNetworkException('u', 'refused'), [502, ''], [200, '{"locales":["pt-BR"]}']], $seen);
        $this->assertSame(['locales' => ['pt-BR']], $client->schema());
        $this->assertSame(['sleep:300', 'sleep:600'], array_values(array_filter($seen, static fn (string $s): bool => str_starts_with($s, 'sleep'))));
    }

    public function testGivesUpAfterTheRetries(): void
    {
        $seen = [];
        $client = $this->client([[500, '{"error":"boom"}'], [500, '{"error":"boom"}']], $seen, 1);
        $error = $this->assertThrows(AstralHttpException::class, fn () => $client->schema());
        $this->assertSame('boom', $error->errorCode);
        $this->assertSame(500, $error->status);
    }

    public function testTypedErrorsAreNotRetried(): void
    {
        $seen = [];
        $client = $this->client([[404, '{"error":"entry_not_found"}'], [401, '{"error":"invalid_key"}']], $seen);
        $notFound = $this->assertThrows(AstralNotFoundException::class, fn () => $client->get('post', 'x'));
        $this->assertTrue(Client::isNotFound($notFound));
        $this->assertSame('entry_not_found', $notFound->errorCode);
        $this->assertThrows(AstralAuthException::class, fn () => $client->schema());
        $this->assertSame(2, count($seen));
    }

    public function testUrlsAndQueries(): void
    {
        $seen = [];
        $client = $this->client([[200, '{"data":[],"meta":{"pageCount":1}}'], [200, '{"data":{"id":"x"}}']], $seen);
        $client->list('post', ['locale' => 'en', 'page' => 2, 'pageSize' => 9, 'sort' => 'date:desc', 'populate' => 1, 'filter' => ['category' => 'design', 'price' => ['lte' => 50], 'tags' => ['a', 'b']]]);
        $this->assertSame('http://cms.test/api/demo/content/post?locale=en&sort=date%3Adesc&page=2&pageSize=9&depth=1&filter%5Bcategory%5D=design&filter%5Bprice%5D%5Blte%5D=50&filter%5Btags%5D%5Bin%5D=a%2Cb auth=yes', $seen[0]);
        $client->preview('tok');
        $this->assertSame('http://cms.test/api/demo/preview?token=tok auth=no', $seen[1], 'preview: no key, no locale');
    }

    public function testImageAttrs(): void
    {
        $media = ['url' => 'http://cms.test/m/a.jpg?f=webp', 'mime' => 'image/jpeg', 'alt' => 'Uma foto', 'width' => 2000, 'height' => 1000, 'optimized' => ['width' => 1800, 'height' => 900], 'srcset' => 'a 320w, b 640w', 'dominantColor' => '#123456'];
        $this->assertSame(['src' => 'http://cms.test/m/a.jpg?f=webp', 'alt' => 'Uma foto', 'loading' => 'lazy', 'decoding' => 'async', 'width' => 1800, 'height' => 900, 'srcset' => 'a 320w, b 640w', 'sizes' => '50vw', 'style' => 'background-color:#123456'], Image::attrs($media, ['sizes' => '50vw']));
        $first = Image::attrs($media, ['priority' => true, 'alt' => '']);
        $this->assertSame(['eager', 'sync', 'high', ''], [$first['loading'], $first['decoding'], $first['fetchpriority'], $first['alt']]);
        $png = Image::attrs(['url' => 'http://cms.test/l.png', 'mime' => 'image/png', 'dominantColor' => '#000', 'srcset' => 'x 1w']);
        $this->assertFalse(isset($png['style']), 'no placeholder behind a transparent PNG');
        $svg = Image::attrs(['url' => 'http://cms.test/l.svg', 'mime' => 'image/svg+xml', 'srcset' => 'x 1w']);
        $this->assertFalse(isset($svg['srcset']), 'no srcset on an SVG');
        $this->assertSame('http://cms.test/m/a.jpg?f=webp&w=640', Image::variantUrl($media, 640));
    }

    public function testRichTextHelpers(): void
    {
        $this->assertSame('<a rel="noopener noreferrer" href="x" target="_blank">', RichText::withExternalRel('<a href="x" target="_blank">'));
        $this->assertSame('<a href="x" target="_blank" rel="nofollow noopener noreferrer">', RichText::withExternalRel('<a href="x" target="_blank" rel="nofollow">'));
        $this->assertSame('Olá & mundo', RichText::plainText('<p>Olá &amp; <b>mundo</b></p>'));
        $this->assertSame("um dois\u{2026}", RichText::plainText('um dois tres', 9));
        $this->assertSame('&lt;script&gt;&quot;&#039;', RichText::escape('<script>"\''));
        $this->assertFalse(RichText::has('   '));
    }
}
