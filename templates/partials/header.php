<?php
/**
 * Brand, the "header" menu from the CMS and the language switcher. The mobile
 * menu is a <details>: it opens and closes without JavaScript.
 *
 * @var App\View $this
 * @var string $locale
 * @var array $settings
 * @var list<array{locale: string, url: string|null}> $alternates
 * @var string $currentPath
 */

$ui = $this->ui($locale);
$menu = $this->cms->menu('header', $locale);
$siteName = (string) ($settings['siteName'] ?? '');
$logo = $settings['logo'] ?? null;

$norm = static fn (?string $url): string => $url ? (rtrim((string) preg_replace('#^https?://[^/]+#', '', $url), '/') ?: '/') : '';
$isCurrent = static function (array $item) use ($norm, $currentPath): bool {
    $url = $norm($item['url'] ?? null);
    if ($url === '' || $url === '#') {
        return false;
    }
    if ($url === $currentPath) {
        return true;
    }
    // An archive owns the pages under it ("/blog" is current on "/blog/post").
    return ($item['kind'] ?? '') === 'archive' && str_starts_with($currentPath, "{$url}/");
};
$locales = [];
foreach ($this->site->format['locales'] as $l) {
    $href = null;
    foreach ($alternates as $alternate) {
        if ($alternate['locale'] === $l) {
            $href = $alternate['url'] ?? null;
        }
    }
    $locales[] = ['locale' => $l, 'href' => $href ?? $this->homeOf($l), 'current' => $l === $locale];
}
?>
    <header class="site-header">
      <div class="container site-header__inner">
        <a class="brand" href="<?= e($this->homeOf($locale)) ?>" aria-label="<?= e($siteName) ?>">
<?php if ($logo): ?>
          <img<?= attrs(['class' => 'brand__logo', 'src' => $logo['url'], 'alt' => '', 'width' => $logo['width'] ?? null, 'height' => $logo['height'] ?? null]) ?>>
<?php else: ?>
          <span class="brand__mark" aria-hidden="true"></span>
          <span><?= e($siteName) ?></span>
<?php endif ?>
        </a>
        <nav class="nav" aria-label="<?= e($ui['menu']) ?>">
          <ul class="nav__list">
<?php foreach ($menu['items'] as $item): ?>
            <li><a<?= attrs(['class' => 'nav__link'] + link_attrs($item) + ['aria-current' => $isCurrent($item) ? 'page' : null]) ?>><?= e($item['label']) ?></a></li>
<?php endforeach ?>
          </ul>
<?php if (count($locales) > 1): ?>
          <ul class="locale-switch" aria-label="<?= e($ui['language']) ?>">
<?php foreach ($locales as $l): ?>
            <li><a<?= attrs(['href' => $l['href'], 'hreflang' => $l['locale'], 'lang' => $l['locale'], 'aria-current' => $l['current'] ? 'true' : null, 'title' => App\I18n::localeName($l['locale'])]) ?>><?= e(explode('-', $l['locale'])[0]) ?></a></li>
<?php endforeach ?>
          </ul>
<?php endif ?>
<?php if ($menu['items']): ?>
          <details class="nav__toggle">
            <summary aria-label="<?= e($ui['menu']) ?>"><?= e($ui['menu']) ?></summary>
            <div class="nav__panel">
              <ul class="nav__list">
<?php foreach ($menu['items'] as $item): ?>
                <li><a<?= attrs(['class' => 'nav__link', 'href' => $item['url'] ?? '#', 'aria-current' => $isCurrent($item) ? 'page' : null]) ?>><?= e($item['label']) ?></a></li>
<?php endforeach ?>
              </ul>
            </div>
          </details>
<?php endif ?>
        </nav>
      </div>
    </header>
