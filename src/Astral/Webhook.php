<?php

declare(strict_types=1);

namespace Astral;

/**
 * Webhook signatures (cms/docs/API.md), a port of toolkits/client/src/webhook.ts:
 * x-cms-timestamp is unix seconds and x-cms-signature is hex HMAC-SHA256 of
 * "{timestamp}.{rawBody}" with the webhook secret. The build-status report to the
 * CMS is signed the same way.
 */
final class Webhook
{
    public const SIGNATURE_WINDOW_MS = 300_000;

    /** The hex HMAC-SHA256 of `payload` with `secret`. */
    public static function hmacSha256(string $secret, string $payload): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /** Compares two strings in time that does not depend on where they differ. */
    public static function safeEqual(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /**
     * Signs a body the way the CMS expects (and the way it signs its own calls).
     *
     * @param int|null $nowMs milliseconds since the epoch; default: now
     * @return array{timestamp: string, signature: string}
     */
    public static function signBody(string $body, string $secret, ?int $nowMs = null): array
    {
        $timestamp = (string) intdiv($nowMs ?? (int) floor(microtime(true) * 1000), 1000);
        return ['timestamp' => $timestamp, 'signature' => self::hmacSha256($secret, "{$timestamp}.{$body}")];
    }

    /**
     * Verifies a webhook delivery. `rawBody` must be the body exactly as received (never
     * re-encoded JSON). `headers` is a name => value map (any case). The timestamp may not
     * be older or newer than five minutes unless `toleranceMs` says otherwise.
     *
     * Returns `['ok' => true, 'event' => ..., 'payload' => [...]]` or `['ok' => false, 'reason' => ...]`
     * with reason missing_secret, missing_headers, stale_timestamp, bad_signature or bad_json.
     *
     * @param array<string, string|list<string>> $headers
     * @return array{ok: bool, reason?: string, event?: string, payload?: array<string, mixed>}
     */
    public static function verify(string $rawBody, array $headers, string $secret, ?int $nowMs = null, int $toleranceMs = self::SIGNATURE_WINDOW_MS): array
    {
        if ($secret === '') {
            return ['ok' => false, 'reason' => 'missing_secret'];
        }
        $timestamp = self::header($headers, 'x-cms-timestamp');
        $signature = self::header($headers, 'x-cms-signature');
        if (!preg_match('/^\d{10}$/', $timestamp) || $signature === '') {
            return ['ok' => false, 'reason' => 'missing_headers'];
        }
        $now = $nowMs ?? (int) floor(microtime(true) * 1000);
        if (abs($now - (int) $timestamp * 1000) > $toleranceMs) {
            return ['ok' => false, 'reason' => 'stale_timestamp'];
        }
        $expected = self::hmacSha256($secret, "{$timestamp}.{$rawBody}");
        if (!self::safeEqual($expected, strtolower($signature))) {
            return ['ok' => false, 'reason' => 'bad_signature'];
        }
        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'reason' => 'bad_json'];
        }
        if (!is_array($payload)) {
            return ['ok' => false, 'reason' => 'bad_json'];
        }
        $event = self::header($headers, 'x-cms-event');
        return ['ok' => true, 'event' => $event !== '' ? $event : (string) ($payload['event'] ?? ''), 'payload' => $payload];
    }

    /**
     * The events inside a delivery: the one event, or every event of a batch.
     *
     * @return list<array{event: string, data: array<string, mixed>}>
     */
    public static function eventsOf(array $payload): array
    {
        if (($payload['event'] ?? null) === 'batch') {
            return array_map(static fn (array $e): array => ['event' => (string) ($e['event'] ?? ''), 'data' => (array) ($e['data'] ?? [])], $payload['events'] ?? []);
        }
        return [['event' => (string) ($payload['event'] ?? ''), 'data' => (array) ($payload['data'] ?? [])]];
    }

    /**
     * Every cache tag the delivery touches (deduplicated), from `data.tags` plus the
     * type/entry pair when the server sent none.
     *
     * @return list<string>
     */
    public static function tagsOf(array $payload): array
    {
        $tags = [];
        foreach (self::eventsOf($payload) as ['event' => $event, 'data' => $data]) {
            foreach ($data['tags'] ?? [] as $tag) {
                $tags[(string) $tag] = true;
            }
            if (!isset($data['tags'])) {
                $isEntry = str_starts_with($event, 'entry.');
                if ($isEntry && is_string($data['type'] ?? null)) {
                    $tags['type:' . $data['type']] = true;
                }
                if ($isEntry && is_string($data['documentId'] ?? null)) {
                    $tags['entry:' . $data['documentId']] = true;
                }
                if ($event === 'menu.updated') {
                    $tags['menus'] = true;
                }
                if ($event === 'settings.updated') {
                    $tags['settings'] = true;
                }
                if ($event === 'schema.updated') {
                    $tags['schema'] = true;
                }
                if ($event === 'taxonomy.updated' && is_string($data['taxonomy'] ?? null)) {
                    $tags['taxonomy:' . $data['taxonomy']] = true;
                }
                if (str_starts_with($event, 'asset.') && is_string($data['id'] ?? null)) {
                    $tags['asset:' . $data['id']] = true;
                }
            }
        }
        return array_keys($tags);
    }

    /** Every public path the delivery names (deduplicated). @return list<string> */
    public static function pathsOf(array $payload): array
    {
        $paths = [];
        foreach (self::eventsOf($payload) as ['data' => $data]) {
            foreach ($data['paths'] ?? [] as $path) {
                $paths[(string) $path] = true;
            }
        }
        return array_keys($paths);
    }

    /** The type apiIds a delivery touches, read from `type:<apiId>` tags. @return list<string> */
    public static function typesOf(array $payload): array
    {
        $types = [];
        foreach (self::tagsOf($payload) as $tag) {
            if (str_starts_with($tag, 'type:')) {
                $types[] = substr($tag, 5);
            }
        }
        return $types;
    }

    /** @param array<string, string|list<string>> $headers */
    private static function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }
        return '';
    }
}
