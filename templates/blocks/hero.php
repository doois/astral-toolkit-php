<?php
/**
 * @var App\View $this
 * @var array $block eyebrow, title, text, image, primary, secondary, align
 * @var string $locale
 * @var int $index the first block of the page gets the h1
 */

$center = ($block['align'] ?? null) === 'center';
$h = $index === 0 ? 'h1' : 'h2';
$primary = !empty($block['primary']['url']) ? $block['primary'] : null;
$secondary = !empty($block['secondary']['url']) ? $block['secondary'] : null;
$image = $block['image'] ?? null;
?>
<section class="<?= e(classes(['block', 'block-hero', 'hero', 'hero--center' => $center])) ?>" data-block="<?= e($block['key'] ?? '') ?>">
  <div class="container">
    <div class="<?= e(classes(['hero__grid', 'hero__grid--image' => $image && !$center])) ?>">
      <div class="hero__content">
<?php if (!empty($block['eyebrow'])): ?>
        <p class="eyebrow"><?= e($block['eyebrow']) ?></p>
<?php endif ?>
        <<?= $h ?> class="display"><?= e($block['title'] ?? '') ?></<?= $h ?>>
<?php if (!empty($block['text'])): ?>
        <p class="hero__text"><?= e($block['text']) ?></p>
<?php endif ?>
<?php if ($primary || $secondary): ?>
        <div class="hero__actions">
<?php if ($primary): ?>
          <a<?= attrs(['class' => 'button'] + link_attrs($primary)) ?>><?= e($primary['label'] ?? '') ?></a>
<?php endif ?>
<?php if ($secondary): ?>
          <a<?= attrs(['class' => 'button button--secondary'] + link_attrs($secondary)) ?>><?= e($secondary['label'] ?? '') ?></a>
<?php endif ?>
        </div>
<?php endif ?>
      </div>
<?php if ($image): ?>
      <div class="hero__media"><?= $this->image($image, ['sizes' => $center ? '(min-width: 74rem) 74rem, 100vw' : '(min-width: 56em) 40vw, 100vw']) ?></div>
<?php endif ?>
    </div>
  </div>
</section>
