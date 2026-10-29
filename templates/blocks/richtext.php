<?php
/**
 * `body` is HTML the CMS sanitized (src/Astral/RichText.php): rich() prints it as it is.
 *
 * @var App\View $this
 * @var array $block body, width
 * @var string $locale
 * @var int $index
 */

if (!Astral\RichText::has($block['body'] ?? null)) {
    return;
}
$wide = ($block['width'] ?? null) === 'wide';
?>
<section class="<?= e(classes(['block', 'block-richtext', 'richtext', 'richtext--wide' => $wide])) ?>" data-block="<?= e($block['key'] ?? '') ?>">
  <div class="<?= e(classes(['container', 'container--narrow' => !$wide])) ?>">
    <div class="prose"><?= rich($block['body']) ?></div>
  </div>
</section>
