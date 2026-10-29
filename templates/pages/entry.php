<?php
/**
 * Picks the template of an entry by its type. Types the toolkit does not know get
 * the generic one: title, then every richtext and blocks field in order.
 *
 * @var App\View $this
 * @var array $entry
 * @var string $locale
 * @var bool $preview
 * @var string $banner
 */

$vars = ['entry' => $entry, 'locale' => $locale, 'preview' => $preview ?? false, 'banner' => $banner ?? ''];
$template = match ($entry['type'] ?? '') {
    'home' => 'pages/home',
    'page' => 'pages/page',
    'post' => 'pages/post',
    default => 'pages/generic',
};
echo $this->render($template, $vars);
