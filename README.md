# Astral toolkit: PHP

Site inicial em PHP puro para o Astral (CMS headless da Doois), feito para
hospedagem compartilhada: Apache + PHP 8.2 ou mais, sem Node em produção, sem
Composer, sem banco de dados. Sobe por FTP e funciona. Tem o mesmo modelo, a mesma
marcação e o mesmo CSS dos toolkits Astro, Next e Nuxt ([DESIGN.md](DESIGN.md)):
cliente com erros tipados e nova tentativa, blocos, menus, idiomas, imagens
responsivas, SEO com hreflang, JSON-LD, sitemap, pré-visualização de rascunhos,
revalidação por webhook, status de build e a ilha `live` da lista de posts.

## O que vem

| Pasta ou arquivo | O quê |
|---|---|
| `index.php`, `.htaccess` | Controlador frontal: tudo que não é arquivo vai para o `index.php` |
| `astral.model.json` | O modelo do site (o mesmo dos outros toolkits) |
| `astral.convert.md` | O mapa do toolkit para a conversão de código do CMS |
| `src/Astral/` | O cliente do Astral em PHP: `Client`, `Urls`, `Image`, `Webhook`, `RichText`, `Render` e as exceções |
| `src/App/` | O site: `Kernel` (rotas e modos), `Cms` (leituras com cache), `PageCache`, `FileCache`, `Routes`, `Site`, `View`, `Revalidator`, `Warmer`, `BuildStatus`, `Assets`, `I18n`, `Env`, `Csp` |
| `templates/` | `layouts/base.php`, `partials/` (cabeçalho, rodapé, cartão de post, paginação, ícones), `pages/` (início, página, post, listagem, 404) e `blocks/` (um arquivo por componente do CMS) |
| `assets/` | `css/` (o CSS do toolkit Astro, arquivo por arquivo), `fonts/`, `js/live-list.js` (a única linha de JavaScript, só em páginas com lista ao vivo), `img/` |
| `bin/` | `warm.php` (preenche o cache), `check.php` (diagnóstico do servidor), `test.php` (testes) |
| `tests/` | Testes de URLs, webhook, cache, cliente e rotas, sem PHPUnit |
| `lighthouserc.json`, `.github/workflows/lighthouse.yml` | Lighthouse CI com mínimo 95 em todas as categorias |
| `var/` | Cache em disco (criado sozinho, fora do git, negado por `.htaccess`) |

## Configuração

Copie `.env.example` para `.env` ao lado do `index.php` e preencha (nunca versione
o `.env`). Variáveis de ambiente de verdade (painel da hospedagem, container) têm
prioridade sobre o arquivo.

| Variável | O quê |
|---|---|
| `ASTRAL_URL` | Endereço do CMS, como `https://cms.doois.com.br` |
| `ASTRAL_PROJECT` | Slug do projeto |
| `ASTRAL_TOKEN` | Chave de leitura (`cms_xxxxxxxx_...`), com `entries:*:read`, `entries:*:preview` e `media.read` |
| `ASTRAL_WEBHOOK_SECRET` | Segredo do webhook do projeto que aponta para `<site>/astral/revalidate` |
| `ASTRAL_BUILD_WEBHOOK_ID` | Opcional: id desse webhook, para reportar ao CMS o status de cada recriação do cache |
| `ASTRAL_MEDIA_URL` | Opcional: host de mídia do CMS (o `MEDIA_URL` dele, como `https://cms-media.doois.com.br`), para a Content-Security-Policy; veja "Segurança" |
| `SITE_URL` | Endereço público do site (sem ele, vale o endereço de cada pedido) |
| `ASTRAL_LOCALE_URLS`, `ASTRAL_LOCALE_DOMAINS` | Opcionais: formato das URLs por idioma e domínios, só enquanto o `/schema` não entregar `localeUrls`/`localeDomains` |
| `ASTRAL_BUILD_LOCALES` | Opcional: só estes idiomas nesta instalação (formato `none`) |
| `ASTRAL_API_TTL`, `ASTRAL_API_STALE` | Opcionais: validade do cache das respostas do CMS (60 s) e por quanto tempo servir a última resposta boa com o CMS fora do ar (7 dias) |
| `ASTRAL_WARM_ON_CLEAR`, `ASTRAL_GZIP`, `ASTRAL_HTML_CACHE_CONTROL`, `ASTRAL_VAR_DIR`, `ASTRAL_DEBUG` | Ajustes finos (veja `.env.example`) |

Sem `ASTRAL_URL` ou `ASTRAL_PROJECT`, o site responde 503 "Site em configuração"
para o público; o motivo vai para o log de erros do PHP e só aparece na página
quando aberta da própria máquina (localhost) ou com `ASTRAL_DEBUG=1`.

No CMS, em Configurações do projeto: endereço do site (`SITE_URL`), endereço de
pré-visualização `https://<site>/preview?token={token}` e um webhook para
`https://<site>/astral/revalidate` com os eventos de conteúdo, mídia, menus,
configurações, taxonomias e schema.

```sh
php bin/check.php                      # PHP, extensões, .env, CMS, permissão de var/
php -S 127.0.0.1:8080 index.php        # servidor local (o index.php imita o .htaccess)
php bin/warm.php                       # preenche o cache lendo o sitemap
php bin/warm.php --clear               # limpa tudo e recria (o "build" deste toolkit)
php bin/test.php                       # testes
```

## Modos de renderização no PHP

Cada tipo tem um `render` no CMS (opção do tipo, entregue pelo `/schema`; ausente
vale `static`). Não há build: o HTML fica em disco em `var/cache/html` e o modo
decide quanto tempo ele vive.

| `render` | No PHP | Quando o conteúdo muda |
|---|---|---|
| `static` | gerado na primeira visita (ou pelo `warm.php`) e guardado sem prazo | o webhook limpa o cache HTML inteiro e recria tudo em seguida |
| `revalidate` | gerado e guardado com as tags (`type:post`, `entry:<id>`, `taxonomy:category`) | o webhook apaga só os arquivos dos caminhos do evento e das páginas com as tags dele |
| `dynamic` | gerado a cada pedido, nunca guardado, `Cache-Control: s-maxage=60` | nada a fazer |

Como funciona um pedido:

1. Página no cache: o arquivo sai direto do disco, já comprimido (gzip), com
   `ETag`; um navegador com a mesma versão recebe `304`. Nenhum acesso ao CMS,
   nenhum template.
2. Fora do cache: redirecionamentos do CMS, tabela de rotas (montada a partir do
   `/schema` em cache, no formato de URL do projeto), conteúdo, template, e a
   página é guardada (se `static` ou `revalidate`).
3. As respostas do CMS também ficam em disco (`var/cache/api`), com validade curta.
   Se o CMS cair, o site continua servindo as páginas em cache e monta as que faltam
   com a última resposta boa de cada leitura. Só sem nenhuma resposta guardada a
   página sai com 503 (sem cache, com `Retry-After`).

Cabeçalhos: `static` e `revalidate` saem com `Cache-Control: public, max-age=0,
must-revalidate` e `ETag` (o navegador sempre confere e quase sempre recebe 304);
`revalidate` leva também `Cache-Tag`. `/preview` sai `private, no-store` com
`noindex` e nunca é guardado. Os assets em `/assets` têm o hash do conteúdo no
nome (`inter-variable.<hash>.woff2`) e saem com cache de um ano, `immutable`.

Compromissos:

- Uma página `static` que embute conteúdo `revalidate` (a home com a lista de posts)
  só muda numa limpeza completa. Para mostrar posts novos antes, use `live` no
  bloco `postlist`: a lista sai no HTML e um elemento de menos de 1 kB busca a
  versão atual em `/astral/posts` (a chave do CMS fica no servidor).
- Em servidores com vários nós, cada um tem o seu `var/`: aponte `ASTRAL_VAR_DIR`
  para um disco compartilhado ou use um CDN na frente.
- Com CDN (Cloudflare, Bunny), chame a API de purga dele em
  `src/App/Revalidator.php`, com os mesmos `paths` e `tags`, e só então aumente o
  `s-maxage` em `ASTRAL_HTML_CACHE_CONTROL`.
- Formato `domain`: o domínio do pedido escolhe o idioma, e o cache separa as
  páginas por domínio. Formato `none`: uma instalação por idioma
  (`ASTRAL_BUILD_LOCALES=en`).

## Revalidação e status de build

O CMS chama `POST /astral/revalidate` em cada mudança. O endpoint confere
`x-cms-timestamp` e `x-cms-signature` (HMAC-SHA256 de `timestamp.corpo` com
`ASTRAL_WEBHOOK_SECRET`, `hash_hmac` + `hash_equals`, janela de 5 minutos) e então:

1. apaga as respostas do CMS com as tags do evento (e o sitemap);
2. para um tipo `revalidate`, apaga o HTML dos caminhos do evento (`data.paths`) e
   das páginas com as tags (`data.tags`);
3. se mudou algo que as páginas `static` usam (um tipo `static`, menus,
   configurações, schema, taxonomias, mídia) ou no evento `site.rebuild`, limpa o
   cache HTML inteiro, responde ao CMS e, já sem o CMS esperando, recria o cache
   lendo o sitemap.

A resposta diz o que foi feito:
`{ ok, event, purged, api, paths, tags, cleared, warm: "scheduled" | "disabled" | "not_needed" }`.

Com `ASTRAL_BUILD_WEBHOOK_ID`, cada limpeza completa seguida da recriação reporta
`building`, depois `success` ou `failed` para `POST /api/<projeto>/webhooks/<id>/status`,
assinado como um webhook (o mesmo corpo que o `scripts/build.mjs` do toolkit Astro
envia). O `php bin/warm.php --clear` do deploy reporta do mesmo jeito.

## Deploy

### Hospedagem compartilhada (FTP ou SFTP)

1. Envie a pasta inteira para a raiz do site (`public_html`, `www`), incluindo o
   `.htaccess`. Não precisa de `vendor/`, `node_modules/` nem build.
2. Crie o `.env` no servidor (a partir do `.env.example`) ou defina as variáveis no
   painel. Nunca envie o `.env` pelo git.
3. Permissões: `var/` precisa ser gravável pelo usuário do PHP. Em geral
   `chmod 775 var` basta (o PHP cria `var/cache/...` sozinho); se o PHP rodar com
   outro usuário, `chmod 777 var` só nessa pasta. Nada mais precisa de escrita.
   O `var/` já é negado pelo `.htaccess` da raiz e por um `.htaccess` próprio, mas
   se a hospedagem permitir, aponte `ASTRAL_VAR_DIR` para uma pasta fora da raiz pública.
4. Abra `https://<site>/` (ou rode `php bin/check.php` por SSH). Para já subir com
   tudo em cache: `php bin/warm.php --clear` por SSH, ou deixe as primeiras visitas
   montarem as páginas.
5. Requisitos do Apache: `mod_rewrite` (obrigatório), `mod_headers`, `mod_deflate`
   ou `mod_brotli` (recomendados). `AllowOverride All` para o `.htaccess` valer.
   Se o site ficar numa subpasta, descomente `RewriteBase`.

### Container Apache (Dokploy)

```Dockerfile
FROM php:8.3-apache
RUN a2enmod rewrite headers deflate expires \
 && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
COPY . /var/www/html/
RUN mkdir -p /var/www/html/var && chown -R www-data:www-data /var/www/html/var
EXPOSE 80
```

1. No Dokploy, crie a aplicação a partir do repositório com esse Dockerfile, porta 80.
2. Variáveis de ambiente: as do `.env.example` (não use `.env` no container).
3. Monte um volume em `/var/www/html/var` para o cache sobreviver a um redeploy, ou
   rode `php bin/warm.php --clear` como comando pós-deploy
   (`docker exec <container> su www-data -s /bin/sh -c "php bin/warm.php --clear"`).
4. No CMS: webhook para `https://<site>/astral/revalidate`; copie o segredo para
   `ASTRAL_WEBHOOK_SECRET` e o id para `ASTRAL_BUILD_WEBHOOK_ID`.

O `php:8.3-apache` já vem com curl, mbstring e zlib. Sem PHP-FPM, a recriação do
cache depois do webhook roda no mesmo processo, depois de a resposta ter sido
enviada (`ignore_user_abort` + `Connection: close`); com PHP-FPM, usa
`fastcgi_finish_request`.

## Como adicionar um bloco

1. No CMS, crie o componente (por exemplo `pricing`) e acrescente-o à lista de
   blocos dos tipos `home` e `page` (ou edite `astral.model.json` numa instalação nova).
2. Crie `templates/blocks/pricing.php`. O nome do arquivo é o apiId do componente:
   isso é o registro, não há outra lista. O template recebe `$block`, `$locale` e
   `$index` (use `$index === 0` para decidir entre `h1` e `h2`). Imprima texto com
   `e()`, atributos com `attrs([...])`, links do CMS com `link_attrs($link)`, imagens
   com `$this->image($media, ['sizes' => '...'])` e campos `richtext` só com `rich()`
   (já sanitizados pelo CMS; veja `src/Astral/RichText.php`). Campos `text` e
   `textarea` nunca vão sem `e()`.
3. Escreva o CSS em `assets/css/blocks.css` seguindo a marcação do
   [DESIGN.md](DESIGN.md), para os outros toolkits poderem copiar.

Um bloco sem template é ignorado (com aviso no log se `ASTRAL_DEBUG=1`), então criar
o componente no CMS antes do código nunca quebra uma página. Depois do deploy, rode
`php bin/warm.php --clear` (ou dispare "Reconstruir site" no CMS) para as páginas
`static` saírem com o bloco novo.

## Idiomas

O CMS entrega as URLs já no formato do projeto (`meta.url`, menus, termos, sitemap);
o site monta as rotas no mesmo formato a partir do `/schema` a cada pedido (em cache).
Os textos de interface (paginação, "Ler mais", 404) ficam em `src/App/I18n.php`:
acrescente um idioma lá quando o projeto ganhar um. As datas usam a extensão `intl`
quando existe; sem ela, os nomes dos meses de `I18n.php`.

## SEO e performance

- `<title>`, descrição, canônica, `hreflang` (com `x-default`), Open Graph e o JSON-LD
  do site (`/jsonld`: Organization e WebSite, mais o WebPage) em toda página;
  `BlogPosting` nos posts (do CMS quando o tipo tem dados estruturados mapeados);
  `FAQPage` no bloco de perguntas.
- `sitemap.xml` vem pronto do CMS (sem endereço do site no projeto, é montado aqui
  com `SITE_URL`). `robots.txt` bloqueia `/preview` e `/astral/`.
- Zero JavaScript por padrão; CSS embutido no HTML (cerca de 20 kB, 4,5 kB com gzip);
  só a fonte de texto é pré-carregada; imagens com o `srcset` do CMS, `width` e
  `height` sempre, a primeira `eager` com `fetchpriority="high"` e as demais `lazy`.
- Lighthouse CI: o workflow roda os testes, sobe o servidor embutido do PHP com os
  segredos do repositório (`ASTRAL_URL`, `ASTRAL_PROJECT`, `ASTRAL_TOKEN`), aquece o
  cache e falha abaixo de 95 em qualquer categoria.

## Segurança: Content-Security-Policy

`src/App/Csp.php` monta a política e `Response` a acrescenta a toda resposta HTML (páginas,
404, preview, 503) e a todo 304, na hora de enviar. Por isso uma página guardada em
`var/cache/html` sai com a política atual, sem limpar cache, e a cópia que o navegador
já tinha é atualizada pelo 304.

```
default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline';
img-src 'self' data: <ASTRAL_URL> <ASTRAL_MEDIA_URL ou https:>; font-src 'self';
connect-src 'self' <ASTRAL_URL>; form-action 'self'; frame-ancestors 'self';
base-uri 'self'; object-src 'none'
```

- As imagens vêm do CMS, mas a API não diz qual é o host de mídia. Com `ASTRAL_MEDIA_URL`
  o `img-src` fica fechado nele; sem ela, aceita qualquer `https:` para não quebrar imagem.
  Num CMS sem `MEDIA_URL`, repita o valor de `ASTRAL_URL` para fechar. Imagens externas em
  campos `richtext` (o CMS permite qualquer `src` http/https) só carregam com a variável vazia.
- Nenhum script inline: o JSON-LD é bloco de dados (o `script-src` não o bloqueia) e a ilha é
  um arquivo. Um bloco novo com JavaScript vai em `assets/js`, nunca em `<script>` inline ou
  `onclick`.
- Vídeos do YouTube ou Vimeo (`frame-src`), `<video>` do CMS (`media-src`) e serviços de
  terceiros (analytics, mapas) precisam da origem acrescentada em `src/App/Csp.php`.

## Cliente

`src/Astral/` é o port do cliente JavaScript do CMS (`toolkits/client`), com o mesmo
comportamento: `new Client(['url' => ..., 'project' => ..., 'token' => ...])` expõe
`list`, `listAll`, `get`, `single`, `menu`, `menus`, `terms`, `term`, `schema`,
`sitemap`, `sitemapXml`, `jsonLd`, `redirects`, `analytics`, `preview`, com exceções
tipadas (`AstralNotFoundException`, `AstralAuthException`, `AstralHttpException`,
`AstralNetworkException`) e nova tentativa em erros de rede e 5xx. Ajudantes:
`Render::mode`, `Urls::pathOf`, `Urls::localeFormat`, `Urls::routePattern`,
`Urls::localeOfPath`, `Image::attrs`, `Webhook::verify`, `Webhook::signBody`,
`Webhook::tagsOf`, `Webhook::pathsOf`. Usa curl quando existe e streams do PHP quando não.

## Demonstração

O conteúdo de demonstração (o estúdio fictício "Vértice") é aplicado a um projeto pelo
`scripts/seed-demo.mjs` do toolkit Astro, que usa este mesmo `astral.model.json`.
