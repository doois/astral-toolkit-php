<?php

declare(strict_types=1);

namespace Tests;

use App\Csp;
use App\PageCache;
use App\Request;
use App\Response;

final class CspTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    public function setUp(): void
    {
        foreach (['ASTRAL_URL', 'ASTRAL_MEDIA_URL'] as $name) {
            $this->saved[$name] = getenv($name);
        }
        putenv('ASTRAL_URL=https://cms.example.com/');
        putenv('ASTRAL_MEDIA_URL');
    }

    public function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        parent::tearDown();
    }

    public function testPolicyComesFromTheConfiguration(): void
    {
        putenv('ASTRAL_MEDIA_URL=https://media.example.com:8443/files/');
        $this->assertSame(
            "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://cms.example.com https://media.example.com:8443; font-src 'self'; connect-src 'self' https://cms.example.com; form-action 'self'; frame-ancestors 'self'; base-uri 'self'; object-src 'none'",
            Csp::policy(),
        );
    }

    public function testImagesFallBackToHttpsWithoutTheMediaHost(): void
    {
        $this->assertContains("img-src 'self' data: https://cms.example.com https:;", Csp::policy());
    }

    public function testARepeatedOriginIsListedOnce(): void
    {
        putenv('ASTRAL_MEDIA_URL=https://cms.example.com');
        $this->assertContains("img-src 'self' data: https://cms.example.com;", Csp::policy());
    }

    public function testEveryHtmlAnswerCarriesThePolicyAndOtherTypesDoNot(): void
    {
        $this->assertSame(Csp::policy(), Response::html('<p>x</p>', 404)->finalHeaders()['content-security-policy'] ?? null);
        $this->assertFalse(isset(Response::json(['ok' => true])->finalHeaders()['content-security-policy']));
        $this->assertFalse(isset(Response::redirect('/x')->finalHeaders()['content-security-policy']));
    }

    public function testCachedHtmlAndIts304CarryThePolicyWithoutItBeingStored(): void
    {
        $dir = $this->tempDir();
        $file = "{$dir}/page.html";
        file_put_contents($file, '<p>x</p>');
        $stored = ['content-type' => 'text/html; charset=utf-8', 'cache-control' => 'public, max-age=0, must-revalidate'];
        $hit = PageCache::answer(new Request('GET', '/x'), 200, $stored, '"abc"', $file, null);
        $this->assertSame(Csp::policy(), $hit->finalHeaders()['content-security-policy'] ?? null);
        $revalidated = PageCache::answer(new Request('GET', '/x', headers: ['if-none-match' => '"abc"']), 200, $stored, '"abc"', $file, null);
        $this->assertSame(304, $revalidated->status);
        $this->assertSame(Csp::policy(), $revalidated->finalHeaders()['content-security-policy'] ?? null);
    }
}
