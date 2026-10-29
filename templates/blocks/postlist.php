<?php
/**
 * The latest posts. With `live`, the server-rendered list is also an island: a tiny
 * custom element (assets/js/live-list.js) fetches /astral/posts in the browser and
 * replaces the cards, so a static page shows new posts before its cache is cleared.
 * It is the only JavaScript the toolkit ships, and only on pages with a live list.
 *
 * @var App\View $this
 * @var array $block title, text, limit, category, live, link
 * @var string $locale
 * @var int $index
 */

$ui = $this->ui($locale);
$limit = min(max((int) ($block['limit'] ?? 3) ?: 3, 1), 12);
$category = trim((string) ($block['category'] ?? '')) ?: null;
$posts = $this->cms->posts($locale, 1, $category, $limit)['data'];
$h = $index === 0 ? 'h1' : 'h2';
$live = ($block['live'] ?? null) === true;
$liveUrl = $live ? '/astral/posts?' . http_build_query(['locale' => $locale, 'limit' => (string) $limit] + ($category ? ['category' => $category] : [])) : null;
?>
<section class="block block-postlist postlist" data-block="<?= e($block['key'] ?? '') ?>">
  <div class="container">
<?php if (!empty($block['title']) || !empty($block['text'])): ?>
    <div class="block__head">
<?php if (!empty($block['title'])): ?>
      <<?= $h ?>><?= e($block['title']) ?></<?= $h ?>>
<?php endif ?>
<?php if (!empty($block['text'])): ?>
      <p class="lede"><?= e($block['text']) ?></p>
<?php endif ?>
    </div>
<?php endif ?>
    <astral-live-list<?= attrs(['data-url' => $liveUrl, 'data-locale' => $locale, 'data-loading' => $ui['loading']]) ?>>
<?php if ($posts): ?>
      <ul class="post-grid">
<?php foreach ($posts as $post): ?>
        <li><?= $this->partial('partials/post-card', ['post' => $post, 'locale' => $locale]) ?></li>
<?php endforeach ?>
      </ul>
<?php else: ?>
      <p class="empty"><?= e($ui['noPosts']) ?></p>
<?php endif ?>
    </astral-live-list>
<?php if (!empty($block['link']['url'])): ?>
    <p class="postlist__foot"><a class="arrow-link" href="<?= e($block['link']['url']) ?>"><?= e($block['link']['label'] ?? '') ?></a></p>
<?php endif ?>
  </div>
</section>
<?php if ($live): ?>
<?= $this->liveScript() ?>
<?php endif ?>
