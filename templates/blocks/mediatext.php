<?php
/**
 * @var App\View $this
 * @var array $block title, text (richtext), image, imageSide, link
 * @var string $locale
 * @var int $index
 */

$h = $index === 0 ? 'h1' : 'h2';
$start = ($block['imageSide'] ?? null) === 'start';
?>
<section class="<?= e(classes(['block', 'block-mediatext', 'mediatext', 'mediatext--start' => $start])) ?>" data-block="<?= e($block['key'] ?? '') ?>">
  <div class="container">
    <div class="mediatext__grid">
      <div class="mediatext__content">
        <<?= $h ?>><?= e($block['title'] ?? '') ?></<?= $h ?>>
<?php if (Astral\RichText::has($block['text'] ?? null)): ?>
        <div class="prose"><?= rich($block['text']) ?></div>
<?php endif ?>
<?php if (!empty($block['link']['url'])): ?>
        <a<?= attrs(['class' => 'arrow-link'] + link_attrs($block['link'])) ?>><?= e($block['link']['label'] ?? '') ?></a>
<?php endif ?>
      </div>
<?php if (!empty($block['image'])): ?>
      <div class="mediatext__media"><?= $this->image($block['image'], ['sizes' => '(min-width: 56em) 36rem, 100vw']) ?></div>
<?php endif ?>
    </div>
  </div>
</section>
