<?php
/**
 * Logos are shown at a fixed height; the alt text (the company name) is the accessible name.
 *
 * @var App\View $this
 * @var array $block title, logos
 * @var string $locale
 * @var int $index
 */

$logos = $block['logos'] ?? [];
if (!$logos) {
    return;
}
?>
<section class="block block-logos logos" data-block="<?= e($block['key'] ?? '') ?>">
  <div class="container">
<?php if (!empty($block['title'])): ?>
    <p class="logos__title eyebrow"><?= e($block['title']) ?></p>
<?php endif ?>
    <ul class="logos__list">
<?php foreach ($logos as $logo): ?>
      <li><?= $this->image($logo, ['sizes' => '160px', 'priority' => false]) ?></li>
<?php endforeach ?>
    </ul>
  </div>
</section>
