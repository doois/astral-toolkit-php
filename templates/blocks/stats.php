<?php
/**
 * @var App\View $this
 * @var array $block title, items (value, label)
 * @var string $locale
 * @var int $index
 */

$h = $index === 0 ? 'h1' : 'h2';
?>
<section class="block block-stats stats" data-block="<?= e($block['key'] ?? '') ?>">
  <div class="container">
<?php if (!empty($block['title'])): ?>
    <div class="block__head">
      <<?= $h ?>><?= e($block['title']) ?></<?= $h ?>>
    </div>
<?php endif ?>
    <ul class="stats__grid">
<?php foreach ($block['items'] ?? [] as $item): ?>
      <li class="stat">
        <span class="stat__value"><?= e($item['value'] ?? '') ?></span>
        <span class="stat__label"><?= e($item['label'] ?? '') ?></span>
      </li>
<?php endforeach ?>
    </ul>
  </div>
</section>
