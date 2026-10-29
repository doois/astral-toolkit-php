<?php

declare(strict_types=1);

// php bin/warm.php [--clear] [--quiet]
//
// Fills the HTML cache from the CMS sitemap (every entry, listing and term
// archive, in every locale, plus the listings' further pages), so the first
// visitor after a deploy gets a cached page. With --clear, empties the HTML and
// CMS caches first: that is the "build" of this toolkit, and it reports
// building, then success or failed, to the CMS when ASTRAL_BUILD_WEBHOOK_ID is set
// (signed like a webhook, exactly as the Astro toolkit's scripts/build.mjs).
// Exit code 0 when every page rendered with 200.

use App\BuildStatus;
use App\Env;
use App\Kernel;
use App\Warmer;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/src/autoload.php';
Env::load($root . '/.env');

if ($missing = Env::missing()) {
    fwrite(STDERR, 'Astral: defina ' . implode(', ', $missing) . " no ambiente ou no .env (veja .env.example).\n");
    exit(2);
}

$clear = in_array('--clear', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$log = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, $line . "\n");
    }
};

$kernel = Kernel::boot($root);
$status = $clear ? BuildStatus::fromEnv() : null;
$result = (new Warmer($kernel, $status))->run($clear, $log);

fwrite(STDOUT, sprintf(
    "warm %s: %d pages in %ss%s%s\n",
    $result['state'],
    $result['pages'],
    $result['seconds'],
    $result['failed'] ? ', failed: ' . implode(', ', $result['failed']) : '',
    $status?->enabled() ? ' (build status reported)' : '',
));
exit($result['ok'] ? 0 : 1);
