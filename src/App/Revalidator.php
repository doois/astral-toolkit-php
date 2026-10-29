<?php

declare(strict_types=1);

namespace App;

use Astral\AstralException;
use Astral\Render;
use Astral\Webhook;

/**
 * POST /astral/revalidate: the CMS calls it on every change (the project's
 * webhook). After checking the signature (HMAC-SHA256 of "timestamp.body" with
 * ASTRAL_WEBHOOK_SECRET, five-minute window), the site:
 *
 * 1. drops the cached CMS answers carrying one of the event's tags (and the sitemap);
 * 2. for a revalidate type, deletes the HTML of the event's paths and of every page
 *    with one of its tags; the next visit renders them again;
 * 3. when something static pages use changed (a static type, menus, settings, the
 *    schema, a taxonomy, an asset) or on "site.rebuild", clears the whole HTML cache,
 *    since there is no build to run, then warms it again after answering and reports
 *    the build status to the CMS (ASTRAL_BUILD_WEBHOOK_ID).
 *
 * The answer says what was done: { ok, event, purged, api, paths, tags, cleared, warm }.
 * With a CDN in front, call its purge API here with the same paths and tags.
 */
final class Revalidator
{
    private const GLOBAL_TAGS = ['menus', 'settings', 'schema'];

    public function __construct(private readonly Kernel $kernel)
    {
    }

    public function handle(Request $request): Response
    {
        $result = Webhook::verify($request->body, $request->headers, Env::webhookSecret());
        if (!$result['ok']) {
            return Response::json(['ok' => false, 'error' => $result['reason']], $result['reason'] === 'missing_secret' ? 503 : 401, ['cache-control' => 'no-store']);
        }
        $payload = $result['payload'];
        $event = (string) ($payload['event'] ?? '');
        $paths = Webhook::pathsOf($payload);
        $tags = Webhook::tagsOf($payload);
        $types = Webhook::typesOf($payload);

        $touchesGlobal = (bool) array_filter($tags, static fn (string $tag): bool => in_array($tag, self::GLOBAL_TAGS, true) || str_starts_with($tag, 'taxonomy:') || str_starts_with($tag, 'asset:'));
        $staticTypes = array_values(array_filter($types, fn (string $apiId): bool => $this->modeOf($apiId) === 'static'));
        $clearAll = $event === 'site.rebuild' || $staticTypes || $touchesGlobal;

        $api = $this->kernel->cms->cache()->purgeTags([...$tags, 'sitemap']);
        if ($touchesGlobal || $event === 'site.rebuild') {
            $api += $this->kernel->cms->cache()->clear();
        }
        $purged = $clearAll ? $this->kernel->pages->clear() : $this->kernel->pages->purgePaths($paths) + $this->kernel->pages->purgeTags($tags);

        $warm = !$clearAll ? 'not_needed' : (Env::bool('ASTRAL_WARM_ON_CLEAR', true) ? 'scheduled' : 'disabled');
        $response = Response::json(['ok' => true, 'event' => $result['event'], 'purged' => $purged, 'api' => $api, 'paths' => $paths, 'tags' => $tags, 'cleared' => (bool) $clearAll, 'warm' => $warm], 200, ['cache-control' => 'no-store']);
        if ($warm === 'scheduled') {
            $kernel = $this->kernel;
            $response->after = static function () use ($kernel): void {
                (new Warmer($kernel->fresh(), BuildStatus::fromEnv()))->run(false);
            };
        } elseif ($clearAll) {
            // Nothing warms the cache: the site rebuilds page by page on the next visits; say so.
            BuildStatus::fromEnv()->report('success', 'Cache limpo; as páginas serão geradas nas próximas visitas');
        }
        return $response;
    }

    /** The render mode of a type; a type the schema no longer has (or a CMS that fails) counts as static. */
    private function modeOf(string $apiId): string
    {
        try {
            $schema = $this->kernel->cms->schema();
        } catch (AstralException) {
            return 'static';
        }
        return Render::type($schema, $apiId) === null ? 'static' : Render::mode($schema, $apiId);
    }
}
