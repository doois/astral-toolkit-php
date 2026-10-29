<?php
/**
 * An entry of a type this toolkit has no template for: its title, then every
 * richtext and blocks field in order.
 *
 * @var App\View $this
 * @var array $entry
 * @var string $locale
 * @var bool $preview
 * @var string $banner
 */

$type = $this->site->type((string) ($entry['type'] ?? '')) ?? ['fields' => [], 'name' => $entry['type'] ?? ''];
$titleField = 'title';
foreach ($type['fields'] ?? [] as $field) {
    if (($field['type'] ?? '') === 'text') {
        $titleField = $field['apiId'];
        break;
    }
}
$title = (string) ($entry[$titleField] ?? $type['name'] ?? $entry['type'] ?? '');
$this->extend('layouts/base', [
    'locale' => $locale,
    'title' => $entry['seo']['title'] ?? $title,
    'description' => $entry['seo']['description'] ?? null,
    'image' => $entry['seo']['image'] ?? null,
    'alternates' => $entry['meta']['alternates'] ?? [],
    'noindex' => $preview || !empty($entry['seo']['noindex']),
    'banner' => $banner,
]);
?>
<header class="container page-header">
  <h1><?= e($title) ?></h1>
</header>
<?php foreach ($type['fields'] ?? [] as $field): ?>
<?php if (($field['type'] ?? '') === 'blocks'): ?>
<?= $this->blocks($entry[$field['apiId']] ?? [], $locale) ?>
<?php elseif (($field['type'] ?? '') === 'richtext' && Astral\RichText::has($entry[$field['apiId']] ?? null)): ?>
<section class="block container container--narrow">
  <div class="prose"><?= rich($entry[$field['apiId']]) ?></div>
</section>
<?php endif ?>
<?php endforeach ?>
