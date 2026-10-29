<?php
/**
 * A preview link that is missing, invalid or expired.
 *
 * @var App\View $this
 * @var string $problem the CMS error code
 */
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Preview</title>
  </head>
  <body style="font-family: system-ui; padding: 2rem">
    <h1>Pré-visualização indisponível</h1>
    <p>O link de pré-visualização é inválido ou expirou (<code><?= e($problem) ?></code>). Abra de novo pelo botão Pré-visualizar no CMS.</p>
  </body>
</html>
