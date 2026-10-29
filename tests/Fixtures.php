<?php

declare(strict_types=1);

namespace Tests;

use App\Cms;
use App\FileCache;
use Astral\AstralNetworkException;
use Astral\Client;

/** Fake CMS answers shaped like the delivery API (fake content only). */
final class Fixtures
{
    /** @return array<string, mixed> */
    public static function schema(array $extra = []): array
    {
        return $extra + [
            'locales' => ['pt-BR', 'en'],
            'defaultLocale' => 'pt-BR',
            'types' => [
                ['apiId' => 'post', 'name' => 'Posts', 'kind' => 'COLLECTION', 'seo' => true, 'render' => 'revalidate', 'urlPattern' => '/blog/{slug}', 'fields' => [['apiId' => 'title', 'type' => 'text']]],
                ['apiId' => 'page', 'name' => 'Páginas', 'kind' => 'COLLECTION', 'seo' => true, 'render' => 'static', 'urlPattern' => '/{slug}', 'fields' => [['apiId' => 'title', 'type' => 'text']]],
                ['apiId' => 'home', 'name' => 'Início', 'kind' => 'SINGLE', 'seo' => true, 'render' => 'static', 'urlPattern' => '/', 'fields' => []],
                ['apiId' => 'author', 'name' => 'Autores', 'kind' => 'COLLECTION', 'seo' => false, 'urlPattern' => null, 'fields' => []],
                ['apiId' => 'settings', 'name' => 'Configurações', 'kind' => 'SINGLE', 'seo' => false, 'urlPattern' => null, 'fields' => []],
            ],
            'taxonomies' => [['apiId' => 'category', 'name' => 'Categorias', 'singularName' => 'Categoria', 'hierarchical' => false, 'types' => ['post'], 'urlPattern' => '/blog/categoria/{slug}']],
            'components' => [],
        ];
    }

    /**
     * A transport answering from a map of "path?query" (without the API base) to
     * [status, body]; unknown paths answer 404. Every call is recorded in $calls.
     *
     * @param array<string, array{0: int, 1: mixed}> $routes
     * @param list<string> $calls
     */
    public static function transport(array $routes, array &$calls = [], bool &$down = false): callable
    {
        return static function (string $url, array $headers) use ($routes, &$calls, &$down): array {
            $path = (string) preg_replace('#^https?://[^/]+/api/[^/]+#', '', $url);
            $calls[] = $path;
            if ($down) {
                throw new AstralNetworkException($url, 'connection refused');
            }
            $bare = strtok($path, '?');
            foreach ([$path, $bare] as $key) {
                if (isset($routes[$key])) {
                    [$status, $body] = $routes[$key];
                    return ['status' => $status, 'body' => is_string($body) ? $body : (string) json_encode($body)];
                }
            }
            return ['status' => 404, 'body' => '{"error":"not_found"}'];
        };
    }

    public static function cms(string $dir, callable $transport, int $ttl = 60): Cms
    {
        $client = new Client(['url' => 'http://cms.test', 'project' => 'demo', 'token' => 'cms_test_key', 'transport' => $transport, 'retries' => 0, 'sleep' => static function (int $ms): void {
        }]);
        return new Cms($client, new FileCache("{$dir}/cache/api"), $ttl);
    }
}
