<?php

declare(strict_types=1);

// php bin/check.php: checks what the site needs on this host and says what is
// wrong in plain words: the PHP version and extensions, the configuration, the
// CMS connection and key, and whether var/ is writable. Prints no secret.

use App\Env;
use Astral\AstralAuthException;
use Astral\AstralException;
use Astral\Client;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/src/autoload.php';
Env::load($root . '/.env');

$ok = true;
$line = static function (bool $pass, string $text) use (&$ok): void {
    $ok = $ok && $pass;
    fwrite(STDOUT, ($pass ? '[ok]   ' : '[erro] ') . $text . "\n");
};
$note = static fn (string $text) => fwrite(STDOUT, '[aviso] ' . $text . "\n");

$line(PHP_VERSION_ID >= 80200, 'PHP ' . PHP_VERSION . ' (precisa de 8.2 ou mais)');
foreach (['json', 'mbstring'] as $extension) {
    $line(extension_loaded($extension), "extensão {$extension}");
}
extension_loaded('curl') ? $line(true, 'extensão curl') : $note('sem curl: o cliente usa streams (allow_url_fopen precisa estar ligado)');
extension_loaded('zlib') ? $line(true, 'extensão zlib (HTML comprimido)') : $note('sem zlib: o HTML sai sem compressão do PHP');

$line(is_file($root . '/.env') || getenv('ASTRAL_URL') !== false, '.env ou variáveis de ambiente');
$missing = Env::missing();
$line(!$missing, $missing ? 'faltam: ' . implode(', ', $missing) : 'ASTRAL_URL e ASTRAL_PROJECT definidos');
Env::token() !== '' ? $line(true, 'ASTRAL_TOKEN definido') : $note('ASTRAL_TOKEN vazio: só funciona com leitura pública ligada no projeto');
Env::webhookSecret() !== '' ? $line(true, 'ASTRAL_WEBHOOK_SECRET definido') : $note('ASTRAL_WEBHOOK_SECRET vazio: /astral/revalidate responde 503');
Env::siteUrl() !== '' ? $line(true, 'SITE_URL = ' . Env::siteUrl()) : $note('SITE_URL vazio: usa o endereço de cada pedido');

$var = Env::get('ASTRAL_VAR_DIR') ?: $root . '/var';
@mkdir($var . '/cache', 0775, true);
$probe = $var . '/cache/.probe';
$line(@file_put_contents($probe, 'ok') !== false, "{$var} gravável pelo PHP");
@unlink($probe);

if (!$missing) {
    $client = new Client(['url' => Env::url(), 'project' => Env::project(), 'token' => Env::token() ?: null, 'retries' => 0]);
    try {
        $schema = $client->schema();
        $types = array_map(static fn (array $t): string => $t['apiId'] . ':' . ($t['render'] ?? 'static'), array_filter($schema['types'], static fn (array $t): bool => !empty($t['urlPattern'])));
        $line(true, 'CMS respondeu /schema: ' . implode(', ', $schema['locales']) . ' (' . ($schema['localeUrls'] ?? 'prefix, padrão') . '); ' . implode(', ', $types));
    } catch (AstralAuthException $error) {
        $line(false, "chave recusada pelo CMS ({$error->errorCode}): confira ASTRAL_TOKEN e as permissões");
    } catch (AstralException $error) {
        $line(false, 'CMS não respondeu: ' . $error->getMessage());
    }
}

exit($ok ? 0 : 1);
