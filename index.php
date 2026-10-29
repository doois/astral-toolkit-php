<?php

declare(strict_types=1);

// Front controller. Apache sends every address that is not an existing file here
// (.htaccess); PHP's built-in server does the same when started with this file as
// its router (`php -S 127.0.0.1:8080 index.php`), and the block below mimics the
// .htaccess rules for it: internal folders answer 404, versioned assets are served
// with a one-year cache, other existing files as they are.

use App\Assets;
use App\Env;
use App\Kernel;
use App\Request;
use App\Response;

require __DIR__ . '/src/autoload.php';

if (PHP_SAPI === 'cli-server') {
    $served = serve_static_file(__DIR__, (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
    if ($served !== null) {
        return $served;
    }
}

Env::load(__DIR__ . '/.env');
$request = Request::fromGlobals();

if ($missing = Env::missing()) {
    config_error($request, $missing)->send($request->method);
    return;
}

try {
    $kernel = Kernel::boot(__DIR__);
    $response = $kernel->handle($request);
} catch (Throwable $error) {
    error_log('[astral] ' . $error);
    $detail = $request->isLocal() || Env::bool('ASTRAL_DEBUG', false) ? '<pre>' . e((string) $error) . '</pre>' : '';
    $response = Response::html(simple_page('Erro no site', '<p>Algo deu errado ao montar esta página. O erro foi registrado no log do PHP.</p>' . $detail), 500, ['cache-control' => 'no-store']);
}

if ($response->after === null) {
    $response->send($request->method);
    return;
}

// Answer first, then do the slow part (the warm after a full clear) with the client gone.
ignore_user_abort(true);
@set_time_limit(0);
$response->headers['connection'] = 'close';
$response->send($request->method);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}
($response->after)();

// ── Helpers of this file ────────────────────────────────────────────────

/**
 * The static part of the built-in server: true when PHP should serve the file
 * itself (return true from a router means "handled"), false to let the server
 * serve it, null for addresses the site renders.
 */
function serve_static_file(string $root, string $path): ?bool
{
    $path = rawurldecode($path);
    if (preg_match('#(^|/)\.(?!well-known/)|^/(var|src|templates|bin|tests|vendor)(/|$)|^/(composer\.(json|lock)|astral\.model\.json|lighthouserc\.json|[A-Z]+\.md|astral\.convert\.md)$#', $path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        return true;
    }
    $file = null;
    $long = false;
    if (preg_match(Assets::HASHED, $path, $m)) {
        $candidate = "{$root}/assets/{$m[1]}.{$m[3]}";
        if (is_file($candidate)) {
            $file = $candidate;
            $long = true;
        }
    } elseif ($path !== '/' && !str_ends_with($path, '.php') && is_file($root . $path)) {
        $file = $root . $path;
    }
    if ($file === null) {
        return null;
    }
    $type = Assets::mime($file);
    header('Content-Type: ' . $type);
    header('Cache-Control: ' . ($long ? 'public, max-age=31536000, immutable' : 'public, max-age=86400'));
    header('Vary: Accept-Encoding');
    $body = (string) file_get_contents($file);
    $compressible = preg_match('#^(text/|application/(json|xml)|image/svg)#', $type) === 1;
    if ($compressible && function_exists('gzencode') && str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')), 'gzip')) {
        $body = (string) gzencode($body, 6);
        header('Content-Encoding: gzip');
    }
    header('Content-Length: ' . strlen($body));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $body;
    }
    return true;
}

/**
 * The site without its configuration: visitors get a short "being set up" page;
 * the details (which variables are missing) go to the PHP error log, and are shown
 * on the page only to the machine itself (localhost) or with ASTRAL_DEBUG=1.
 *
 * @param list<string> $missing
 */
function config_error(Request $request, array $missing): Response
{
    $names = implode(', ', $missing);
    error_log("[astral] configuração incompleta: defina {$names} no ambiente ou no .env (veja .env.example)");
    $body = '<p>O site está sendo configurado. Volte em alguns minutos.</p>';
    if ($request->isLocal() || Env::bool('ASTRAL_DEBUG', false)) {
        $body .= '<p><strong>Para quem administra o site:</strong> faltam as variáveis <code>' . e($names) . '</code>. '
            . 'Copie <code>.env.example</code> para <code>.env</code> ao lado do <code>index.php</code> e preencha, ou defina no painel da hospedagem. '
            . 'Depois rode <code>php bin/check.php</code>.</p>';
    } else {
        $body .= '<p class="small">Detalhes no log de erros do PHP.</p>';
    }
    return Response::html(simple_page('Site em configuração', $body), 503, ['cache-control' => 'no-store', 'retry-after' => '300', 'x-robots-tag' => 'noindex']);
}

function simple_page(string $title, string $body): string
{
    return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . e($title) . '</title></head>'
        . '<body style="font-family: system-ui, sans-serif; padding: 2rem; max-width: 44rem; margin: auto; line-height: 1.5"><h1>' . e($title) . '</h1>' . $body . '</body></html>';
}
