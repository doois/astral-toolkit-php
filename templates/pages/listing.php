<?php
/**
 * The blog: a page of posts with the category filter (links, so the filter is
 * crawlable and works without JavaScript) and pagination.
 *
 * @var App\View $this
 * @var string $locale
 * @var string $title
 * @var string|null $description
 * @var list<array> $posts
 * @var array $meta page, pageCount
 * @var string $base the listing's page-1 URL
 * @var string $allUrl the listing of every post (the "all" filter)
 * @var list<array> $terms
 * @var array|null $current
 * @var list<array> $alternates
 */

$ui = $this->ui($locale);
$page = (int) $meta['page'];
$pageCount = (int) $meta['pageCount'];
$this->extend('layouts/base', [
    'locale' => $locale,
    'title' => $page > 1 ? "{$title} (" . $this->pageLabel($locale, $page, $pageCount) . ')' : $title,
    'description' => $description,
    'alternates' => $alternates,
    'canonical' => $page > 1 ? $this->path : $base,
    'currentPath' => $allUrl,
]);
?>
<div class="container">
  <header class="page-header">
    <p class="eyebrow"><?= e($ui['blog']) ?></p>
    <h1><?= e($title) ?></h1>
<?php if ($description): ?>
    <p class="lede"><?= e($description) ?></p>
<?php endif ?>
  </header>
<?php if ($terms): ?>
  <nav class="filters" aria-label="<?= e($ui['categories']) ?>">
    <a<?= attrs(['href' => $allUrl, 'aria-current' => $current ? null : 'page']) ?>><?= e($ui['allCategories']) ?></a>
<?php foreach ($terms as $term): ?>
    <a<?= attrs(['href' => $term['url'] ?? $allUrl, 'aria-current' => ($current['id'] ?? null) === $term['id'] ? 'page' : null]) ?>><?= e($term['name']) ?><?php if (is_int($term['count'] ?? null)): ?><span class="visually-hidden"> (<?= e($term['count']) ?>)</span><?php endif ?></a>
<?php endforeach ?>
  </nav>
<?php endif ?>
<?php if ($posts): ?>
  <ul class="post-grid">
<?php foreach ($posts as $post): ?>
    <li><?= $this->partial('partials/post-card', ['post' => $post, 'locale' => $locale, 'headingLevel' => 'h2']) ?></li>
<?php endforeach ?>
  </ul>
<?php else: ?>
  <p class="empty"><?= e($ui['noPosts']) ?></p>
<?php endif ?>
<?= $this->partial('partials/pagination', ['page' => $page, 'pageCount' => $pageCount, 'base' => $base, 'locale' => $locale]) ?>
</div>
