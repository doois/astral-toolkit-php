<?php
/**
 * @var App\View $this
 * @var array $block title, text, button, tone
 * @var string $locale
 * @var int $index
 */

$accent = ($block['tone'] ?? null) !== 'neutral';
$h = $index === 0 ? 'h1' : 'h2';
$button = !empty($block['button']['url']) ? $block['button'] : null;
?>
<section class="<?= e(classes(['block', 'block-cta', 'cta', 'cta--accent' => $accent])) ?>" data-block="<?= e($block['key'] ?? '') ?>">
  <div class="container">
    <div class="cta__box">
      <div>
        <<?= $h ?>><?= e($block['title'] ?? '') ?></<?= $h ?>>
<?php if (!empty($block['text'])): ?>
        <p><?= e($block['text']) ?></p>
<?php endif ?>
      </div>
<?php if ($button): ?>
      <a<?= attrs(['class' => classes(['button', $accent ? 'button--accent' : ''])] + link_attrs($button)) ?>><?= e($button['label'] ?? '') ?></a>
<?php endif ?>
    </div>
  </div>
</section>
