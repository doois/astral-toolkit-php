<?php
/**
 * A post: header, cover (the page's first image, so eager and high priority), body,
 * author. JSON-LD comes from the CMS when the type has structured data mapped
 * (meta.jsonLd); otherwise the BlogPosting is built here.
 *
 * @var App\View $this
 * @var array $entry title, excerpt, cover, body, date, author, category
 * @var string $locale
 * @var bool $preview
 * @var string $banner
 */

$ui = $this->ui($locale);
$category = $entry['category'][0] ?? null;
$author = $entry['author'] ?? null;
$cover = $entry['cover'] ?? $entry['seo']['image'] ?? null;
$url = $entry['meta']['url'] ?? null;
$absolute = $url ? $this->absolute($url) : null;

$jsonLd = $entry['meta']['jsonLd'] ?? null;
if (!$jsonLd) {
    $jsonLd = ['@type' => 'BlogPosting', '@id' => "{$absolute}#article", 'headline' => $entry['title'] ?? ''];
    if (!empty($entry['excerpt'])) {
        $jsonLd['description'] = $entry['excerpt'];
    }
    if ($cover) {
        $jsonLd['image'] = $cover['url'];
    }
    if (!empty($entry['date'])) {
        $jsonLd['datePublished'] = $entry['date'];
    }
    $jsonLd['dateModified'] = $entry['meta']['updatedAt'] ?? null;
    if ($author) {
        $jsonLd['author'] = ['@type' => 'Person', 'name' => $author['name'] ?? ''];
    }
    $jsonLd['inLanguage'] = $locale;
    $jsonLd['mainEntityOfPage'] = $absolute;
}

$this->extend('layouts/base', [
    'locale' => $locale,
    'title' => $entry['seo']['title'] ?? $entry['title'] ?? '',
    'description' => $entry['seo']['description'] ?? $entry['excerpt'] ?? null,
    'image' => $cover,
    'canonical' => $entry['seo']['canonical'] ?? null,
    'alternates' => $entry['meta']['alternates'] ?? [],
    'noindex' => $preview || !empty($entry['seo']['noindex']),
    'jsonLd' => [$jsonLd],
    'ogType' => 'article',
    'banner' => $banner,
]);
?>
<article class="post">
  <header class="container post__header">
<?php if ($category): ?>
    <p class="eyebrow"><?php if (!empty($category['url'])): ?><a href="<?= e($category['url']) ?>"><?= e($category['name']) ?></a><?php else: ?><?= e($category['name']) ?><?php endif ?></p>
<?php endif ?>
    <h1><?= e($entry['title'] ?? '') ?></h1>
<?php if (!empty($entry['excerpt'])): ?>
    <p class="lede post__lede"><?= e($entry['excerpt']) ?></p>
<?php endif ?>
    <div class="post__byline">
<?php if (!empty($author['photo'])): ?>
      <?= $this->image($author['photo'], ['sizes' => '36px', 'alt' => '', 'priority' => false]) ?>
<?php endif ?>
<?php if ($author): ?>
      <span><?= e($ui['by']) ?> <strong><?= e($author['name'] ?? '') ?></strong></span>
<?php endif ?>
<?php if (!empty($entry['date'])): ?>
      <time datetime="<?= e($entry['date']) ?>"><?= e($this->date($entry['date'], $locale)) ?></time>
<?php endif ?>
    </div>
  </header>
<?php if (!empty($entry['cover'])): ?>
  <div class="container post__cover"><?= $this->image($entry['cover'], ['sizes' => '(min-width: 74rem) 74rem, 100vw', 'priority' => true]) ?></div>
<?php endif ?>
  <div class="container post__body">
<?php if (Astral\RichText::has($entry['body'] ?? null)): ?>
    <div class="prose"><?= rich($entry['body']) ?></div>
<?php endif ?>
  </div>
<?php if ($author): ?>
  <footer class="container post__footer">
    <div class="author-card">
<?php if (!empty($author['photo'])): ?>
      <?= $this->image($author['photo'], ['sizes' => '64px', 'alt' => '', 'priority' => false]) ?>
<?php endif ?>
      <div>
        <h3><?= e($author['name'] ?? '') ?></h3>
<?php if (!empty($author['bio'])): ?>
        <p><?= e($author['bio']) ?></p>
<?php endif ?>
      </div>
    </div>
  </footer>
<?php endif ?>
</article>
