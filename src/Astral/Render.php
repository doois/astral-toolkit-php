<?php

declare(strict_types=1);

namespace Astral;

/**
 * How each type's pages are generated (docs/TOOLKITS.md, "options.render"), a port
 * of toolkits/client/src/render.ts. A server that does not deliver `render` yet
 * means every type is static.
 */
final class Render
{
    public const MODES = ['static', 'revalidate', 'dynamic'];

    /** The render mode of a type (an apiId or a schema type): its `render`, "static" when missing or unknown. */
    public static function mode(array $schema, string|array $type): string
    {
        $def = is_string($type) ? self::type($schema, $type) : $type;
        $mode = $def['render'] ?? null;
        return is_string($mode) && in_array($mode, self::MODES, true) ? $mode : 'static';
    }

    /** Whether a type's pages are built ahead of time (static or revalidate) rather than per request. */
    public static function isPrerendered(array $schema, string|array $type): bool
    {
        return self::mode($schema, $type) !== 'dynamic';
    }

    /** The types that have pages of their own (a URL pattern). @return list<array<string, mixed>> */
    public static function routedTypes(array $schema): array
    {
        return array_values(array_filter($schema['types'] ?? [], static fn (array $t): bool => !empty($t['urlPattern'])));
    }

    /** @return array<string, mixed>|null */
    public static function type(array $schema, string $apiId): ?array
    {
        foreach ($schema['types'] ?? [] as $type) {
            if (($type['apiId'] ?? null) === $apiId) {
                return $type;
            }
        }
        return null;
    }
}
