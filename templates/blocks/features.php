<?php
/**
 * @var App\View $this
 * @var array $block title, text, items (icon, title, text, link), columns
 * @var string $locale
 * @var int $index
 */

$columns = in_array($block['columns'] ?? '', ['2', '3', '4'], true) ? $block['columns'] : '3';
$h = $index === 0 ? 'h1' : 'h2';
?>
<section class="block block-features features" data-block="<?= e($block['key'] ?? '') ?>">
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
    <ul class="features__grid features__grid--<?= e($columns) ?>">
<?php foreach ($block['items'] ?? [] as $item): ?>
      <li class="feature">
        <?= $this->partial('partials/icon', ['name' => $item['icon'] ?? null]) ?>
        <h3><?= e($item['title'] ?? '') ?></h3>
<?php if (!empty($item['text'])): ?>
        <p><?= e($item['text']) ?></p>
<?php endif ?>
<?php if (!empty($item['link']['url'])): ?>
        <a<?= attrs(['class' => 'arrow-link'] + link_attrs($item['link'])) ?>><?= e($item['link']['label'] ?? '') ?></a>
<?php endif ?>
      </li>
<?php endforeach ?>
    </ul>
  </div>
</section>
