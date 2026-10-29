<?php
/**
 * Each question is a <details>: expandable without JavaScript, and the answers are
 * in the HTML for search engines. FAQPage structured data goes along.
 *
 * @var App\View $this
 * @var array $block title, text, items (question, answer as richtext)
 * @var string $locale
 * @var int $index
 */

use Astral\RichText;

$items = array_values(array_filter($block['items'] ?? [], static fn (array $item): bool => !empty($item['question'])));
if (!$items) {
    return;
}
$h = $index === 0 ? 'h1' : 'h2';
$jsonLd = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(static fn (array $item): array => ['@type' => 'Question', 'name' => $item['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => RichText::plainText((string) ($item['answer'] ?? ''))]], $items)];
?>
<section class="block block-faq faq" data-block="<?= e($block['key'] ?? '') ?>">
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
    <div class="faq__list">
<?php foreach ($items as $item): ?>
      <details class="faq__item">
        <summary><?= e($item['question']) ?></summary>
<?php if (RichText::has($item['answer'] ?? null)): ?>
        <div class="faq__answer prose"><?= rich($item['answer']) ?></div>
<?php endif ?>
      </details>
<?php endforeach ?>
    </div>
  </div>
  <script type="application/ld+json"><?= json_script($jsonLd) ?></script>
</section>
