<?php
/**
 * @var App\View $this
 * @var string $locale
 * @var array $settings
 */

$ui = $this->ui($locale);
$menu = $this->cms->menu('footer', $locale);
$social = array_values(array_filter($settings['social'] ?? [], static fn (array $s): bool => !empty($s['url']) && !empty($s['label'])));
$year = gmdate('Y');
$home = $this->homeOf($locale);
?>
    <footer class="site-footer">
      <div class="container">
        <div class="site-footer__grid">
          <div class="site-footer__about">
            <a class="brand" href="<?= e($home) ?>"><?= e($settings['siteName'] ?? '') ?></a>
<?php if (!empty($settings['description'])): ?>
            <p><?= e($settings['description']) ?></p>
<?php endif ?>
<?php if (!empty($settings['contactEmail'])): ?>
            <p><a href="mailto:<?= e($settings['contactEmail']) ?>"><?= e($settings['contactEmail']) ?></a></p>
<?php endif ?>
          </div>
<?php if ($menu['items']): ?>
          <nav aria-label="<?= e($menu['name']) ?>">
            <h3><?= e($menu['name']) ?></h3>
            <ul>
<?php foreach ($menu['items'] as $item): ?>
              <li><a<?= attrs(link_attrs($item)) ?>><?= e($item['label']) ?></a></li>
<?php endforeach ?>
            </ul>
          </nav>
<?php endif ?>
<?php if ($social): ?>
          <div>
            <h3>Social</h3>
            <ul>
<?php foreach ($social as $s): ?>
              <li><a<?= attrs(link_attrs($s)) ?>><?= e($s['label']) ?></a></li>
<?php endforeach ?>
            </ul>
          </div>
<?php endif ?>
        </div>
        <div class="site-footer__bottom">
          <span><?= e(($settings['footerText'] ?? '') ?: ($settings['siteName'] ?? '') . ", {$year}") ?></span>
          <a href="<?= e($home) ?>"><?= e($ui['home']) ?></a>
        </div>
      </div>
    </footer>
