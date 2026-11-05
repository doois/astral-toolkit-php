# astral.convert.md

Read by the CMS's code-conversion job: how this toolkit is laid out and what a
conversion may change. Stack: plain PHP 8.2+ templates (no framework, no
Composer needed), plain CSS (no preprocessor, no `!important`), zero JavaScript
except the `postlist` live island. Content comes only from the Astral delivery
API through `src/App/Cms.php`; field shapes follow `astral.model.json`.

## Writable paths

- `templates/blocks/`
- `templates/partials/`
- `templates/layouts/`
- `templates/pages/`
- `assets/css/`
- `assets/fonts/`
- `assets/img/`
- `favicon.svg`
- `src/App/I18n.php`
- `astral.model.json`
- `DESIGN.md`

## Tokens

`assets/css/tokens.css`: every color (`--paper*` surfaces, `--ink*` text,
`--accent*`, `--line*`, `--focus`; light in `:root`, dark under
`@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) }` and
`:root[data-theme="dark"]`), font stacks (`--font-text`, `--font-display`,
`--font-mono`), type scale (`--text-xs` to `--text-4xl`), rhythm (`--space-1` to
`--space-9`, `--section`, `--gutter`), layout widths (`--container`,
`--container-narrow`, `--measure`), radii (`--radius-sm` to `--radius-full`) and
motion (`--ease`, `--dur`). A re-brand changes this file first; new tokens go here.
Keep `--ink` and `--accent` on `--paper` at 4.5:1 or more in both schemes.

Styles: global in `assets/css/base.css` (fonts, reset, headings, `.prose`,
`.button`, `.container`, utilities) and `assets/css/layout.css` (header, footer,
listing, pagination, post, 404, preview banner); per block in
`assets/css/blocks.css`, one commented section per component. `assets/css/index.css`
imports them all; the site inlines the result in every page (keep it under 32 kB).

## Blocks

- A component apiId maps to `templates/blocks/<apiId>.php` (`hero` is
  `hero.php`, `mediatext` is `mediatext.php`). That file is the registration:
  `$this->blocks()` in `src/App/View.php` renders a block when its file exists and
  skips it otherwise.
- Variables: `$block` (the delivered component: `component`, `key`, its fields),
  `$locale`, `$index` (`0` gets `h1`, the rest `h2`). Print text with `e()`,
  attributes with `attrs([...])`, CMS links with `link_attrs($link)`, images with
  `$this->image($media, ['sizes' => ...])`, richtext fields only with `rich()`.
- Wrapper: `<section class="block block-<apiId> <apiId>" data-block="...">` with a
  `.container` inside; optional `.block__head` with the title and `.lede`.

Steps to add `pricing` (`title` text, `plans` repeatable with `name`, `price`):

1. `templates/blocks/pricing.php`:

```php
<?php $h = $index === 0 ? 'h1' : 'h2'; ?>
<section class="block block-pricing pricing" data-block="<?= e($block['key'] ?? '') ?>">
  <div class="container">
<?php if (!empty($block['title'])): ?>
    <div class="block__head"><<?= $h ?>><?= e($block['title']) ?></<?= $h ?>></div>
<?php endif ?>
    <ul class="pricing__grid">
<?php foreach ($block['plans'] ?? [] as $plan): ?>
      <li class="plan"><h3><?= e($plan['name'] ?? '') ?></h3><span class="plan__price"><?= e($plan['price'] ?? '') ?></span></li>
<?php endforeach ?>
    </ul>
  </div>
</section>
```

2. Styles under a `/* Pricing */` section of `assets/css/blocks.css`.
3. The component in `astral.model.json` (`components[]`, and its apiId in the
   `blocks` field of `home` and `page`); the markup noted in `DESIGN.md`.

## Layouts

- `templates/layouts/base.php`: the document (head with title, description,
  canonical, hreflang, Open Graph, JSON-LD, inlined CSS, font preload), skip link,
  header, `$banner`, `<main id="content">`, footer. Pages call
  `$this->extend('layouts/base', [...])` with `locale`, `title`, `description`,
  `image`, `canonical`, `alternates`, `noindex`, `jsonLd`, `ogType`, `currentPath`.
- Header: `templates/partials/header.php` (brand from `settings.logo`/`siteName`,
  the `header` menu, language switcher, `<details>` mobile menu). Footer:
  `templates/partials/footer.php` (about, the `footer` menu, social links, footer text).
- Page templates: `templates/pages/home.php`, `page.php`, `post.php`,
  `listing.php`, `not-found.php`, `generic.php`; `entry.php` picks one by type.
  Shared pieces: `templates/partials/post-card.php`, `pagination.php`, `icon.php`.

## Assets

- Fonts: `assets/fonts/*.woff2`, declared with `@font-face` at the top of
  `assets/css/base.css` as `url("/fonts/<file>")` (the site rewrites those to
  versioned `/assets/fonts/<file>.<hash>.woff2` URLs). The text face is preloaded in
  `templates/layouts/base.php` with `$this->asset('fonts/<file>')`. Replace the files,
  the `@font-face` blocks and the preload together.
- Logo: from the CMS (`settings.logo`, `<img class="brand__logo">`, inverted in dark
  mode by CSS); without one, `.brand__mark` plus the site name. Favicon: `favicon.svg`.
- Other images and icons: `assets/img/`, referenced with `$this->asset('img/<file>')`
  (versioned, cached for a year). Feature icons are inline SVG paths in
  `templates/partials/icon.php`.

## Pages

Routing, caching and revalidation are infrastructure shared by every Astral
toolkit. Never touch: `index.php`, `.htaccess`, `src/Astral/` (the client),
`src/App/` except `I18n.php`, `src/autoload.php`, `src/helpers.php`, `bin/`,
`tests/`, `assets/js/live-list.js`, `composer.json`, `lighthouserc.json`,
`.github/`, `.env`, `.env.example`, `.gitignore`, `var/`.

## Notes

The markup and class names follow `DESIGN.md`, shared with the Astro, Next and
Nuxt toolkits: a conversion that changes markup keeps the class names stable.

The Content-Security-Policy (`src/App/Csp.php`) allows no inline JavaScript: no
`<script>` with code, no `onclick`, no third-party origins unless added there.
Styles may be inline.

Converted sites keep the live edit wiring of the preview: every layout prints `$banner`, which carries the banner and the CMS agent.
