<?php

declare(strict_types=1);

namespace Tests;

use Astral\Urls;

final class UrlsTest extends TestCase
{
    private function format(string $mode, array $domains = []): array
    {
        return ['locales' => ['pt-BR', 'en'], 'defaultLocale' => 'pt-BR', 'mode' => $mode, 'domains' => $domains];
    }

    public function testLocaleFormatReadsTheSchemaAndFallsBackToPrefix(): void
    {
        $schema = ['locales' => ['pt-BR', 'en'], 'defaultLocale' => 'pt-BR'];
        $this->assertSame('prefix', Urls::localeFormat($schema)['mode']);
        $this->assertSame('prefix-all', Urls::localeFormat($schema + ['localeUrls' => 'prefix-all'])['mode']);
        $this->assertSame('none', Urls::localeFormat($schema + ['localeUrls' => 'prefix'], ['mode' => 'none'])['mode']);
        $this->assertSame('prefix', Urls::localeFormat($schema + ['localeUrls' => 'bogus'])['mode']);
        $this->assertSame(['en' => 'https://en.example.com'], Urls::localeFormat($schema + ['localeDomains' => ['en' => 'https://en.example.com']])['domains']);
    }

    public function testFillPatternPlacesTheLocale(): void
    {
        $values = ['slug' => 'ola', 'locale' => 'en'];
        $this->assertSame('/en/blog/ola', Urls::fillPattern('/blog/{slug}', $values, $this->format('prefix')));
        $this->assertSame('/blog/ola', Urls::fillPattern('/blog/{slug}', ['slug' => 'ola', 'locale' => 'pt-BR'], $this->format('prefix')));
        $this->assertSame('/pt-BR/blog/ola', Urls::fillPattern('/blog/{slug}', ['slug' => 'ola', 'locale' => 'pt-BR'], $this->format('prefix-all')));
        $this->assertSame('/blog/ola', Urls::fillPattern('/blog/{slug}', $values, $this->format('none')));
        $this->assertSame('https://en.example.com/blog/ola', Urls::fillPattern('/blog/{slug}', $values, $this->format('domain', ['en' => 'https://en.example.com'])));
        $this->assertSame('/en/x/ola', Urls::fillPattern('/{locale}/x/{slug}', $values, $this->format('prefix')));
        $this->assertSame('/en', Urls::fillPattern('/', ['slug' => '', 'locale' => 'en'], $this->format('prefix')));
        $this->assertSame('/', Urls::fillPattern('/', ['slug' => '', 'locale' => 'pt-BR'], $this->format('prefix')));
    }

    public function testListingAndRoutePatterns(): void
    {
        $prefix = $this->format('prefix');
        $this->assertSame('/blog', Urls::listingPath('/blog/{slug}'));
        $this->assertSame('/', Urls::listingPath('/{slug}'));
        $this->assertSame('/en/blog', Urls::listingUrl('/blog/{slug}', 'en', $prefix));
        $this->assertSame('/en/blog/[slug]', Urls::routePattern('/blog/{slug}', 'en', $prefix));
        $this->assertSame('/[slug]', Urls::routePattern('/{slug}', 'pt-BR', $prefix));
        $this->assertSame('/en', Urls::routePattern('/', 'en', $prefix));
        $this->assertSame('/', Urls::routePattern('/', 'pt-BR', $prefix));
        $this->assertSame('/blog/[slug]', Urls::routePattern('/blog/{slug}', 'en', $this->format('domain', ['en' => 'https://en.example.com'])));
    }

    public function testLocaleOfPathAndPathOf(): void
    {
        $prefix = $this->format('prefix');
        $this->assertSame(['locale' => 'en', 'path' => '/blog'], Urls::localeOfPath('/en/blog/', $prefix));
        $this->assertSame(['locale' => 'en', 'path' => '/'], Urls::localeOfPath('/en', $prefix));
        $this->assertSame(['locale' => 'pt-BR', 'path' => '/blog'], Urls::localeOfPath('/blog', $prefix));
        $domain = $this->format('domain', ['en' => 'https://en.example.com', 'pt-BR' => 'https://example.com']);
        $this->assertSame('en', Urls::localeOfPath('/blog', $domain, 'en.example.com:443')['locale']);
        $this->assertSame('/blog/x', Urls::pathOf('https://en.example.com/blog/x/'));
        $this->assertSame('/', Urls::pathOf(['meta' => ['url' => 'https://example.com']]));
        $this->assertSame(null, Urls::pathOf(null));
        $this->assertSame('/en/contato', Urls::localizePath('/contato', 'en', $prefix));
        $this->assertSame('/en/contato', Urls::localizePath('/en/contato', 'en', $prefix));
        $this->assertSame('https://x.com/a', Urls::localizePath('https://x.com/a', 'en', $prefix));
    }
}
