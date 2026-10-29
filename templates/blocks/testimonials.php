<?php
/**
 * @var App\View $this
 * @var array $block title, items (quote, name, role, photo)
 * @var string $locale
 * @var int $index
 */

$h = $index === 0 ? 'h1' : 'h2';
?>
<section class="block block-testimonials testimonials" data-block="<?= e($block['key'] ?? '') ?>">
  <div class="container">
<?php if (!empty($block['title'])): ?>
    <div class="block__head">
      <<?= $h ?>><?= e($block['title']) ?></<?= $h ?>>
    </div>
<?php endif ?>
    <ul class="testimonials__grid">
<?php foreach ($block['items'] ?? [] as $item): ?>
      <li>
        <figure class="testimonial">
          <blockquote><?= e($item['quote'] ?? '') ?></blockquote>
          <figcaption>
            <?= $this->image($item['photo'] ?? null, ['sizes' => '40px', 'alt' => '']) ?>
            <span><strong><?= e($item['name'] ?? '') ?></strong> <?= e($item['role'] ?? '') ?></span>
          </figcaption>
        </figure>
      </li>
<?php endforeach ?>
    </ul>
  </div>
</section>
