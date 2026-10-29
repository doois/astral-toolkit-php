<?php

declare(strict_types=1);

namespace Tests;

/**
 * A minimal base class, so the tests run with `php bin/test.php` on any host
 * (no Composer, no PHPUnit). Methods named test* run in a fresh instance each.
 */
abstract class TestCase
{
    public int $assertions = 0;
    /** @var list<string> */
    private array $dirs = [];

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            \App\FileCache::removeTree($dir);
        }
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/astral-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        return $this->dirs[] = $dir;
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new AssertionFailed(($message ? "{$message}: " : '') . 'expected ' . self::show($expected) . ', got ' . self::show($actual));
        }
    }

    protected function assertTrue(bool $condition, string $message = 'expected true'): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new AssertionFailed($message);
        }
    }

    protected function assertFalse(bool $condition, string $message = 'expected false'): void
    {
        $this->assertTrue(!$condition, $message);
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertTrue(str_contains($haystack, $needle), ($message ? "{$message}: " : '') . "expected to find {$needle}");
    }

    /** @param class-string<\Throwable> $class */
    protected function assertThrows(string $class, callable $work): \Throwable
    {
        $this->assertions++;
        try {
            $work();
        } catch (\Throwable $error) {
            if ($error instanceof $class) {
                return $error;
            }
            throw new AssertionFailed("expected {$class}, got " . $error::class . ': ' . $error->getMessage());
        }
        throw new AssertionFailed("expected {$class}, nothing thrown");
    }

    private static function show(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

final class AssertionFailed extends \RuntimeException
{
}
