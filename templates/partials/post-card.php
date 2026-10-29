<?php
/**
 * A post in a grid (the postlist block and the blog listing).
 *
 * @var App\View $this
 * @var array $post
 * @var string $locale
 * @var string $headingLevel "h2" or "h3"
 */

$headingLevel = ($headingLevel ?? 'h3') === 'h2' ? 'h2' : 'h3';
$category = $post['category'][0] ?? null;
$url = $post['meta']['url'] ?? '#';
?>
<article class="post-card">
<?php if (!empty($post['cover'])): ?>
  <a class="post-card__media" href="<?= e($url) ?>" tabindex="-1" aria-hidden="true"><?= $this->image($post['cover'], ['sizes' => '(min-width: 64em) 24rem, (min-width: 40em) 50vw, 100vw', 'alt' => '']) ?></a>
<?php endif ?>
  <div class="post-card__meta">
<?php if (!empty($post['date'])): ?>
    <time datetime="<?= e($post['date']) ?>"><?= e($this->date($post['date'], $locale)) ?></time>
<?php endif ?>
<?php if ($category): ?>
<?php if (!empty($category['url'])): ?>
    <a href="<?= e($category['url']) ?>"><?= e($category['name']) ?></a>
<?php else: ?>
    <span><?= e($category['name']) ?></span>
<?php endif ?>
<?php endif ?>
  </div>
  <<?= $headingLevel ?> class="post-card__title"><a href="<?= e($url) ?>"><?= e($post['title'] ?? '') ?></a></<?= $headingLevel ?>>
<?php if (!empty($post['excerpt'])): ?>
  <p class="post-card__excerpt"><?= e($post['excerpt']) ?></p>
<?php endif ?>
</article>
