<?php
/**
 * The document around every page: head (title, description, canonical, hreflang,
 * Open Graph, JSON-LD), the inlined stylesheet, the preloaded text font, header
 * and footer. Same markup as the Astro toolkit's src/layouts/Base.astro.
 *
 * @var App\View $this
 * @var string $content the page
 * @var string $locale
 * @var string $title
 * @var string|null $description
 * @var array|null $image share image (og:image)
 * @var string|null $canonical the page's own canonical path or URL; default: the current path
 * @var list<array{locale: string, url: string|null}> $alternates the same page in other locales
 * @var bool $noindex
 * @var list<array> $jsonLd schema.org items added to the site's graph
 * @var string $ogType "website" or "article"
 * @var string|null $currentPath the path the menu marks as current
 * @var string $banner HTML after the header (the preview banner and the CMS's live edit agent)
 */

$description ??= null;
$image ??= null;
$canonical ??= null;
$alternates ??= [];
$noindex ??= false;
$jsonLd ??= [];
$ogType ??= 'website';
$currentPath ??= null;
$banner ??= '';

$ui = $this->ui($locale);
$settings = $this->cms->settings($locale);
$siteGraph = $this->cms->siteJsonLd();
$siteName = ($settings['siteName'] ?? '') ?: App\Env::project();
$fullTitle = $title && $title !== $siteName ? "{$title} · {$siteName}" : $siteName;
$metaDescription = $description ?: ($settings['description'] ?? '') ?: '';

$path = rtrim($this->path, '/') ?: '/';
$canonicalUrl = $this->absolute($canonical ?: $path);
$ogImage = $image['url'] ?? $settings['logo']['url'] ?? null;

// hreflang: every locale the page exists in, plus x-default on the default locale.
$languages = [];
foreach ($alternates as $alternate) {
    if (!empty($alternate['url'])) {
        $languages[] = ['locale' => $alternate['locale'], 'href' => $this->absolute($alternate['url'])];
    }
}
$xDefault = null;
foreach ($languages as $language) {
    if ($language['locale'] === $this->site->format['defaultLocale']) {
        $xDefault = $language;
    }
}
$xDefault ??= $languages[0] ?? null;

$webPage = ['@type' => 'WebPage', '@id' => "{$canonicalUrl}#webpage", 'url' => $canonicalUrl, 'name' => $fullTitle];
if ($metaDescription) {
    $webPage['description'] = $metaDescription;
}
$webPage['inLanguage'] = $locale;
if ($ogImage) {
    $webPage['image'] = $ogImage;
}
$graph = [...($siteGraph['@graph'] ?? []), $webPage, ...$jsonLd];
?>
<!doctype html>
<html lang="<?= e($locale) ?>">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($fullTitle) ?></title>
<?php if ($metaDescription): ?>
    <meta name="description" content="<?= e($metaDescription) ?>">
<?php endif ?>
    <link rel="canonical" href="<?= e($canonicalUrl) ?>">
<?php if ($noindex): ?>
    <meta name="robots" content="noindex, nofollow">
<?php endif ?>
<?php if (count($languages) > 1): ?>
<?php foreach ($languages as $language): ?>
    <link rel="alternate" hreflang="<?= e($language['locale']) ?>" href="<?= e($language['href']) ?>">
<?php endforeach ?>
<?php if ($xDefault): ?>
    <link rel="alternate" hreflang="x-default" href="<?= e($xDefault['href']) ?>">
<?php endif ?>
<?php endif ?>
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#faf8f5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#141316" media="(prefers-color-scheme: dark)">
    <meta property="og:type" content="<?= e($ogType) ?>">
    <meta property="og:site_name" content="<?= e($siteName) ?>">
    <meta property="og:title" content="<?= e($fullTitle) ?>">
<?php if ($metaDescription): ?>
    <meta property="og:description" content="<?= e($metaDescription) ?>">
<?php endif ?>
    <meta property="og:url" content="<?= e($canonicalUrl) ?>">
    <meta property="og:locale" content="<?= e(str_replace('-', '_', $locale)) ?>">
<?php if ($ogImage): ?>
    <meta property="og:image" content="<?= e($ogImage) ?>">
<?php if (!empty($image['width']) && !empty($image['height'])): ?>
    <meta property="og:image:width" content="<?= e($image['width']) ?>">
    <meta property="og:image:height" content="<?= e($image['height']) ?>">
<?php endif ?>
<?php endif ?>
    <meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="preload" href="<?= e($this->asset('fonts/inter-variable.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <script type="application/ld+json"><?= json_script(['@context' => 'https://schema.org', '@graph' => $graph]) ?></script>
    <style><?= $this->css() ?></style>
  </head>
  <body>
    <a class="skip-link" href="#content"><?= e($ui['skipToContent']) ?></a>
<?= $this->partial('partials/header', ['locale' => $locale, 'settings' => $settings, 'alternates' => $alternates, 'currentPath' => $currentPath ?? $path]) ?>
<?= $banner ?>
    <main id="content" tabindex="-1">
<?= $content ?>
    </main>
<?= $this->partial('partials/footer', ['locale' => $locale, 'settings' => $settings]) ?>
  </body>
</html>
