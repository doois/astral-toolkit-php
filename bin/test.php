<?php

declare(strict_types=1);

// php bin/test.php [filter]
// Runs every test* method of tests/*Test.php (see tests/TestCase.php). No Composer
// or PHPUnit needed; exit code 1 when anything fails.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/src/autoload.php';
require $root . '/tests/TestCase.php';
require $root . '/tests/Fixtures.php';

$filter = $argv[1] ?? '';
$passed = 0;
$failed = [];
$assertions = 0;
$started = microtime(true);

foreach (glob($root . '/tests/*Test.php') ?: [] as $file) {
    require_once $file;
    $class = 'Tests\\' . basename($file, '.php');
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test') || ($filter !== '' && !str_contains("{$class}::{$method}", $filter))) {
            continue;
        }
        /** @var Tests\TestCase $test */
        $test = new $class();
        try {
            $test->setUp();
            $test->$method();
            $passed++;
            echo '.';
        } catch (Throwable $error) {
            $failed[] = "{$class}::{$method}\n  " . $error->getMessage() . "\n  at " . basename($error->getFile()) . ':' . $error->getLine();
            echo 'F';
        } finally {
            $test->tearDown();
            $assertions += $test->assertions;
        }
    }
}

echo "\n\n";
foreach ($failed as $failure) {
    echo "FAIL {$failure}\n\n";
}
printf("%d passed, %d failed, %d assertions in %.2fs\n", $passed, count($failed), $assertions, microtime(true) - $started);
exit($failed ? 1 : 0);
