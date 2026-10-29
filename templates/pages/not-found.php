<?php
/**
 * The 404 page (sent with status 404), in the locale of the address.
 *
 * @var App\View $this
 * @var string $locale
 */

$ui = $this->ui($locale);
$this->extend('layouts/base', ['locale' => $locale, 'title' => $ui['notFoundTitle'], 'noindex' => true]);
$homes = $this->site->format['locales'];
?>
<div class="container not-found">
  <p class="eyebrow">404</p>
  <h1><?= e($ui['notFoundTitle']) ?></h1>
  <p class="lede"><?= e($ui['notFoundText']) ?></p>
  <p><?php foreach ($homes as $i => $home): ?><?= $i > 0 ? ' · ' : '' ?><a href="<?= e($this->homeOf($home)) ?>" hreflang="<?= e($home) ?>" lang="<?= e($home) ?>"><?= e($home === $locale ? $ui['backHome'] : App\I18n::localeName($home)) ?></a><?php endforeach ?></p>
</div>
