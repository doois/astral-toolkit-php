<?php

declare(strict_types=1);

// Template helpers. Every value printed by a template goes through e() (or
// attrs(), which escapes too). The one exception is rich(): HTML of a richtext
// field, which the CMS sanitized (see src/Astral/RichText.php).

use Astral\RichText;

if (!function_exists('e')) {
    /** Escapes a value for HTML text or a quoted attribute. */
    function e(mixed $value): string
    {
        return RichText::escape(is_bool($value) ? ($value ? 'true' : 'false') : $value);
    }
}

if (!function_exists('attrs')) {
    /**
     * Renders attributes in order: null and false are left out, true prints the bare
     * name, everything else is escaped. Starts with a space when not empty.
     *
     * @param array<string, mixed> $attributes
     */
    function attrs(array $attributes): string
    {
        $out = '';
        foreach ($attributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            $out .= $value === true ? ' ' . e($name) : ' ' . e($name) . '="' . e($value) . '"';
        }
        return $out;
    }
}

if (!function_exists('classes')) {
    /**
     * A class list like Astro's class:list: strings are kept, `['name' => bool]` entries
     * are kept when true.
     *
     * @param array<int|string, mixed> $list
     */
    function classes(array $list): string
    {
        $out = [];
        foreach ($list as $key => $value) {
            if (is_int($key)) {
                if (is_string($value) && $value !== '') {
                    $out[] = $value;
                }
            } elseif ($value) {
                $out[] = $key;
            }
        }
        return implode(' ', $out);
    }
}

if (!function_exists('rich')) {
    /**
     * The HTML of a richtext field, as delivered by the CMS (already sanitized there).
     * Never pass anything else: text and textarea fields are not sanitized.
     */
    function rich(mixed $html): string
    {
        return RichText::has($html) ? (string) $html : '';
    }
}

if (!function_exists('json_script')) {
    /** JSON for a <script type="application/ld+json">, like JSON.stringify with "<" escaped. */
    function json_script(mixed $value): string
    {
        return str_replace('<', '\\u003c', (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}

if (!function_exists('link_attrs')) {
    /**
     * href, target and rel of a CMS `link` component (or a menu item).
     *
     * @param array<string, mixed> $link
     * @return array<string, string|null>
     */
    function link_attrs(array $link): array
    {
        $newTab = !empty($link['newTab']);
        return ['href' => (string) ($link['url'] ?? '#'), 'target' => $newTab ? '_blank' : null, 'rel' => $newTab ? 'noopener' : null];
    }
}
