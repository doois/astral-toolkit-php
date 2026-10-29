<?php
/**
 * @var App\View $this
 * @var array $block title, images, columns
 * @var string $locale
 * @var int $index
 */

$images = $block['images'] ?? [];
if (!$images) {
    return;
}
$columns = ($block['columns'] ?? null) === '2' ? '2' : '3';
$h = $index === 0 ? 'h1' : 'h2';
$sizes = $columns === '2' ? '(min-width: 74rem) 36rem, 50vw' : '(min-width: 74rem) 24rem, (min-width: 48em) 33vw, 50vw';
?>
<section class="block block-gallery gallery" data-block="<?= e($block['key'] ?? '') ?>">
  <div class="container">
<?php if (!empty($block['title'])): ?>
    <div class="block__head">
      <<?= $h ?>><?= e($block['title']) ?></<?= $h ?>>
    </div>
<?php endif ?>
    <ul class="gallery__grid gallery__grid--<?= e($columns) ?>">
<?php foreach ($images as $image): ?>
      <li>
        <figure class="gallery__item">
          <?= $this->image($image, ['sizes' => $sizes]) ?>
<?php if (!empty($image['caption'])): ?>
          <figcaption><?= e($image['caption']) ?></figcaption>
<?php endif ?>
        </figure>
      </li>
<?php endforeach ?>
    </ul>
  </div>
</section>
