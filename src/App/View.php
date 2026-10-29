<?php

declare(strict_types=1);

namespace App;

use Astral\Image;
use Astral\Urls;

/**
 * Renders the plain PHP templates in templates/. Inside a template `$this` is the
 * view: `$this->partial()`, `$this->image()`, `$this->blocks()`, `$this->cms`...
 * A template may call `$this->extend('layouts/base', [...])`: its output becomes
 * the layout's `$content`.
 *
 * One view renders one page, so it also carries the page's state: the image
 * counter (the first image is eager with fetchpriority="high", the rest lazy) and
 * whether the live-list script was already added.
 */
final class View
{
    private int $images = 0;
    private bool $liveScript = false;
    /** @var array{0: string, 1: array<string, mixed>}|null */
    private ?array $layout = null;

    public function __construct(
        public readonly Site $site,
        public readonly Cms $cms,
        public readonly string $path,
        private readonly string $templates,
    ) {
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        $outer = $this->layout;
        $this->layout = null;
        $content = $this->capture($template, $vars);
        $layout = $this->layout;
        $this->layout = $outer;
        if ($layout !== null) {
            return $this->render($layout[0], ['content' => $content] + $layout[1]);
        }
        return $content;
    }

    /** @param array<string, mixed> $vars */
    public function partial(string $template, array $vars = []): string
    {
        return $this->render($template, $vars);
    }

    /** @param array<string, mixed> $props */
    public function extend(string $layout, array $props): void
    {
        $this->layout = [$layout, $props];
    }

    /**
     * A `blocks` field: one partial per component, templates/blocks/<component>.php.
     * A block of a component this site has no template for is skipped (and logged when
     * ASTRAL_DEBUG is on), so creating a component in the CMS first never breaks a page.
     *
     * @param list<array<string, mixed>>|null $blocks
     */
    public function blocks(?array $blocks, string $locale): string
    {
        $out = '';
        $index = 0;
        foreach ($blocks ?? [] as $block) {
            $component = (string) ($block['component'] ?? '');
            if (!preg_match('/^[a-z0-9_-]+$/i', $component) || !is_file("{$this->templates}/blocks/{$component}.php")) {
                if (Env::bool('ASTRAL_DEBUG', false)) {
                    error_log("[astral] bloco \"{$component}\" sem template em templates/blocks");
                }
                continue;
            }
            $out .= $this->render("blocks/{$component}", ['block' => $block, 'locale' => $locale, 'index' => $index++]);
        }
        return $out;
    }

    /**
     * A CMS image from imageAttrs. The first image of the page is eager with
     * fetchpriority="high"; `priority` decides by hand (true or false).
     *
     * @param array<string, mixed>|null $media
     * @param array{sizes?: string, priority?: bool, alt?: string, class?: string} $options
     */
    public function image(?array $media, array $options = []): string
    {
        if (!$media || empty($media['url'])) {
            return '';
        }
        $first = $options['priority'] ?? ($this->images === 0);
        $this->images++;
        $imageOptions = ['priority' => $first];
        if (isset($options['sizes'])) {
            $imageOptions['sizes'] = $options['sizes'];
        }
        if (array_key_exists('alt', $options)) {
            $imageOptions['alt'] = $options['alt'];
        }
        $attrs = Image::attrs($media, $imageOptions);
        $attrs['class'] = $options['class'] ?? null;
        return '<img' . attrs($attrs) . '>';
    }

    /** @return array<string, string> */
    public function ui(string $locale): array
    {
        return I18n::strings($locale);
    }

    public function pageLabel(string $locale, int $current, int $total): string
    {
        return I18n::page($locale, $current, $total);
    }

    public function date(?string $value, string $locale): string
    {
        return I18n::formatDate($value, $locale);
    }

    /** The home of a locale ("/" or "/en"). */
    public function homeOf(string $locale): string
    {
        return Urls::fillPattern('/', ['slug' => '', 'locale' => $locale], $this->site->format);
    }

    /** An absolute URL on the site (SITE_URL, or the request's origin when unset). */
    public function absolute(?string $path): string
    {
        $path = (string) $path;
        if (preg_match('#^https?://#', $path)) {
            return $path;
        }
        return $this->site->siteUrl . '/' . ltrim($path, '/');
    }

    public function asset(string $path): string
    {
        return $this->site->assets->url($path);
    }

    public function css(): string
    {
        return $this->site->assets->inlineCss();
    }

    /** The live-list module, once per page (only pages with a live postlist get any JavaScript). */
    public function liveScript(): string
    {
        if ($this->liveScript) {
            return '';
        }
        $this->liveScript = true;
        return '<script type="module" src="' . e($this->asset('js/live-list.js')) . '"></script>';
    }

    /** @param array<string, mixed> $vars */
    private function capture(string $template, array $vars): string
    {
        $file = "{$this->templates}/{$template}.php";
        if (!is_file($file)) {
            throw new \RuntimeException("Template {$template} not found");
        }
        ob_start();
        try {
            (function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })->call($this, $file, $vars);
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
        return (string) ob_get_clean();
    }
}
