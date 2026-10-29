<?php

declare(strict_types=1);

namespace App;

use Astral\Webhook;

/**
 * Reports "building", then "success" or "failed" to the CMS
 * (POST /api/<project>/webhooks/<id>/status), signed like a webhook, exactly as
 * the Astro toolkit's scripts/build.mjs does. There is no build in PHP: the
 * equivalent is a full cache clear followed by a warm, so that is what reports.
 * The admin shows the status and e-mails the people who look after the site
 * when it fails. Without ASTRAL_BUILD_WEBHOOK_ID (or the secret) nothing is sent.
 */
final class BuildStatus
{
    /** @var callable(string, array<string, string>, string): int */
    private $post;

    /** @param (callable(string, array<string, string>, string): int)|null $post */
    public function __construct(
        private readonly string $url,
        private readonly string $project,
        private readonly string $webhookId,
        private readonly string $secret,
        private readonly string $siteUrl,
        ?callable $post = null,
    ) {
        $this->post = $post ?? self::curlPost(...);
    }

    public static function fromEnv(): self
    {
        return new self(Env::url(), Env::project(), Env::buildWebhookId(), Env::webhookSecret(), Env::siteUrl());
    }

    public function enabled(): bool
    {
        return $this->url !== '' && $this->project !== '' && $this->webhookId !== '' && $this->secret !== '';
    }

    /** The JSON body, key for key what build.mjs sends. */
    public function body(string $status, ?string $message = null): string
    {
        $data = ['status' => $status];
        if ($message !== null && $message !== '') {
            $data['message'] = $message;
        }
        if ($this->siteUrl !== '' && $status === 'success') {
            $data['url'] = $this->siteUrl;
        }
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Sends one status; never throws (a report that fails is logged, the site carries on). */
    public function report(string $status, ?string $message = null): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $body = $this->body($status, $message);
        $signed = Webhook::signBody($body, $this->secret);
        $endpoint = "{$this->url}/api/" . rawurlencode($this->project) . '/webhooks/' . rawurlencode($this->webhookId) . '/status';
        try {
            $code = ($this->post)($endpoint, ['content-type' => 'application/json', 'x-cms-timestamp' => $signed['timestamp'], 'x-cms-signature' => $signed['signature']], $body);
        } catch (\Throwable $error) {
            error_log("[astral] build status {$status} not sent: " . $error->getMessage());
            return false;
        }
        if ($code < 200 || $code >= 300) {
            error_log("[astral] build status {$status} not accepted: {$code}");
            return false;
        }
        return true;
    }

    /** @param array<string, string> $headers */
    private static function curlPost(string $url, array $headers, string $body): int
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        if (!function_exists('curl_init')) {
            $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $lines), 'content' => $body, 'timeout' => 10, 'ignore_errors' => true]]);
            @file_get_contents($url, false, $context);
            /** @var list<string> $http_response_header */
            preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m);
            return (int) ($m[1] ?? 0);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $lines, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $ok = curl_exec($ch);
        $code = $ok === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($ok === false) {
            throw new \RuntimeException($error);
        }
        return $code;
    }
}
