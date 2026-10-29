<?php
/**
 * @var App\View $this
 * @var array $entry blocks
 * @var string $locale
 * @var bool $preview
 * @var string $banner
 */

$settings = $this->cms->settings($locale);
$alternates = array_map(fn (string $l): array => ['locale' => $l, 'url' => $this->homeOf($l)], $this->site->format['locales']);
$this->extend('layouts/base', [
    'locale' => $locale,
    'title' => $entry['seo']['title'] ?? $settings['siteName'] ?? '',
    'description' => $entry['seo']['description'] ?? $settings['description'] ?? null,
    'image' => $entry['seo']['image'] ?? null,
    'canonical' => $entry['seo']['canonical'] ?? null,
    'alternates' => $alternates,
    'noindex' => $preview || !empty($entry['seo']['noindex']),
    'banner' => $banner,
]);
echo $this->blocks($entry['blocks'] ?? [], $locale);
