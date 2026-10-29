<?php

declare(strict_types=1);

namespace Astral;

/**
 * Rich text safety, a port of toolkits/client/src/richtext.ts.
 *
 * A `richtext` field arrives as HTML the CMS already sanitized (an allow list of
 * tags and attributes, http/https/mailto/tel links only, no scripts, no event
 * handlers, no inline styles). It is safe to echo as it is, as long as:
 *
 * 1. Only values that came from a richtext field of the delivery API are echoed raw
 *    (templates use rich(), which does exactly that). Plain `text` and `textarea`
 *    fields are NOT sanitized: always escape them with e().
 * 2. Never request `richtext=markdown` and render the markdown without a sanitizer:
 *    the markdown allows raw HTML.
 * 3. Never concatenate user input (query strings, form values) into that HTML.
 * 4. Links may be external: the CMS keeps `target` and `rel` as the editor set them.
 *    `withExternalRel` adds rel="noopener noreferrer" to target="_blank" links when a
 *    site wants that guarantee regardless of the editor's input.
 */
final class RichText
{
    /** Whether a value is HTML the CMS produced for a richtext field (empty counts as nothing to render). */
    public static function has(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /** Adds rel="noopener noreferrer" to every target="_blank" link that lacks it. */
    public static function withExternalRel(string $html): string
    {
        return (string) preg_replace_callback('/<a\b[^>]*target="_blank"[^>]*>/i', static function (array $m): string {
            $tag = $m[0];
            if (preg_match('/\brel="[^"]*noopener/i', $tag)) {
                return $tag;
            }
            if (preg_match('/\brel="([^"]*)"/i', $tag, $rel)) {
                return str_replace($rel[0], str_replace('rel=" ', 'rel="', 'rel="' . $rel[1] . ' noopener noreferrer"'), $tag);
            }
            return (string) preg_replace('/^<a\b/i', '<a rel="noopener noreferrer"', $tag);
        }, $html);
    }

    /** Strips tags from a rich text (an excerpt, a meta description), collapsing whitespace. */
    public static function plainText(string $html, ?int $maxLength = null): string
    {
        $text = (string) preg_replace('/<[^>]+>/', ' ', $html);
        $text = str_replace(['&nbsp;', '&amp;', '&lt;', '&gt;', '&quot;', '&#39;'], [' ', '&', '<', '>', '"', "'"], $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($maxLength === null || mb_strlen($text) <= $maxLength) {
            return $text;
        }
        return preg_replace('/\s+\S*$/u', '', mb_substr($text, 0, $maxLength)) . "\u{2026}";
    }

    /** Escapes text for an HTML attribute or text node (for the fields the CMS does not sanitize). */
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
