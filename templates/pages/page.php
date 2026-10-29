<?php
/**
 * A page is its blocks. When the first block is not a hero, the title opens the page.
 *
 * @var App\View $this
 * @var array $entry title, blocks
 * @var string $locale
 * @var bool $preview
 * @var string $banner
 */

$blocks = $entry['blocks'] ?? [];
$opensWithHero = ($blocks[0]['component'] ?? null) === 'hero';
$this->extend('layouts/base', [
    'locale' => $locale,
    'title' => $entry['seo']['title'] ?? $entry['title'] ?? '',
    'description' => $entry['seo']['description'] ?? null,
    'image' => $entry['seo']['image'] ?? null,
    'canonical' => $entry['seo']['canonical'] ?? null,
    'alternates' => $entry['meta']['alternates'] ?? [],
    'noindex' => $preview || !empty($entry['seo']['noindex']),
    'banner' => $banner,
]);
?>
<?php if (!$opensWithHero): ?>
<header class="container page-header">
  <h1><?= e($entry['title'] ?? '') ?></h1>
</header>
<?php endif ?>
<?= $this->blocks($blocks, $locale) ?>
