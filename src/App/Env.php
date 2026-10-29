<?php

declare(strict_types=1);

namespace App;

/**
 * Configuration comes from the environment, never from the repository: real
 * environment variables (the host panel, the container) win, then the `.env`
 * file next to index.php. A tiny KEY=value reader, no dependency.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $file = [];
    private static bool $loaded = false;

    /** The variables the site cannot run without. */
    public const REQUIRED = ['ASTRAL_URL', 'ASTRAL_PROJECT'];

    public static function load(string $path): void
    {
        self::$file = is_file($path) ? self::parse((string) file_get_contents($path)) : [];
        self::$loaded = true;
    }

    /**
     * KEY=value lines; `#` comments, optional `export`, single or double quotes.
     *
     * @return array<string, string>
     */
    public static function parse(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                continue;
            }
            $value = $m[2];
            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $value, $q)) {
                $value = stripcslashes($q[1]);
            } elseif (preg_match("/^'([^']*)'/", $value, $q)) {
                $value = $q[1];
            } else {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $value));
            }
            $out[$m[1]] = $value;
        }
        return $out;
    }

    public static function get(string $name, string $default = ''): string
    {
        $real = getenv($name);
        if ($real === false && isset($_SERVER[$name]) && is_string($_SERVER[$name])) {
            $real = $_SERVER[$name];
        }
        $value = $real !== false ? $real : (self::$file[$name] ?? null);
        $value = $value === null ? '' : trim($value);
        return $value !== '' ? $value : $default;
    }

    public static function bool(string $name, bool $default): bool
    {
        $value = strtolower(self::get($name));
        if ($value === '') {
            return $default;
        }
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $name, int $default): int
    {
        $value = self::get($name);
        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return list<string> */
    public static function missing(): array
    {
        return array_values(array_filter(self::REQUIRED, static fn (string $name): bool => self::get($name) === ''));
    }

    public static function url(): string
    {
        return rtrim(self::get('ASTRAL_URL'), '/');
    }

    public static function project(): string
    {
        return self::get('ASTRAL_PROJECT');
    }

    public static function token(): string
    {
        return self::get('ASTRAL_TOKEN');
    }

    public static function webhookSecret(): string
    {
        return self::get('ASTRAL_WEBHOOK_SECRET');
    }

    public static function buildWebhookId(): string
    {
        return self::get('ASTRAL_BUILD_WEBHOOK_ID');
    }

    /** SITE_URL without a trailing slash; empty when unset (the request's origin is used then). */
    public static function siteUrl(): string
    {
        return rtrim(self::get('SITE_URL'), '/');
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }
}
