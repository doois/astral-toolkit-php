<?php

declare(strict_types=1);

// PSR-4 for the two namespaces of the toolkit, so the site runs as uploaded
// (no Composer, no vendor/). composer.json maps the same folders for projects
// that prefer `composer dump-autoload`.

spl_autoload_register(static function (string $class): void {
    foreach (['Astral\\' => __DIR__ . '/Astral/', 'App\\' => __DIR__ . '/App/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

require_once __DIR__ . '/helpers.php';
