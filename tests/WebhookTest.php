<?php

declare(strict_types=1);

namespace Tests;

use App\BuildStatus;
use Astral\Webhook;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_only';

    /** @return array<string, string> */
    private function signed(string $body, ?int $nowMs = null): array
    {
        $s = Webhook::signBody($body, self::SECRET, $nowMs);
        return ['X-CMS-Timestamp' => $s['timestamp'], 'x-cms-signature' => $s['signature']];
    }

    public function testSignatureMatchesTheCmsFormula(): void
    {
        $s = Webhook::signBody('{"a":1}', self::SECRET, 1_800_000_000_123);
        $this->assertSame('1800000000', $s['timestamp']);
        $this->assertSame(hash_hmac('sha256', '1800000000.{"a":1}', self::SECRET), $s['signature']);
    }

    public function testVerifyAcceptsAFreshSignedBody(): void
    {
        $body = '{"event":"entry.published","projectId":"p","sentAt":"x","data":{"type":"post","paths":["/blog/a"],"tags":["type:post","entry:1"]}}';
        $result = Webhook::verify($body, $this->signed($body), self::SECRET);
        $this->assertTrue($result['ok']);
        $this->assertSame('entry.published', $result['event']);
        $this->assertSame(['/blog/a'], Webhook::pathsOf($result['payload']));
        $this->assertSame(['type:post', 'entry:1'], Webhook::tagsOf($result['payload']));
        $this->assertSame(['post'], Webhook::typesOf($result['payload']));
    }

    public function testVerifyRejects(): void
    {
        $body = '{"event":"menu.updated","data":{}}';
        $now = (int) (microtime(true) * 1000);
        $this->assertSame('missing_secret', Webhook::verify($body, $this->signed($body), '')['reason']);
        $this->assertSame('missing_headers', Webhook::verify($body, [], self::SECRET)['reason']);
        $this->assertSame('stale_timestamp', Webhook::verify($body, $this->signed($body, $now - 301_000), self::SECRET)['reason']);
        $this->assertSame('stale_timestamp', Webhook::verify($body, $this->signed($body, $now + 301_000), self::SECRET)['reason']);
        $this->assertTrue(Webhook::verify($body, $this->signed($body, $now - 290_000), self::SECRET)['ok'], 'inside the window');
        $this->assertSame('bad_signature', Webhook::verify($body . ' ', $this->signed($body), self::SECRET)['reason']);
        $this->assertSame('bad_signature', Webhook::verify($body, $this->signed($body), 'another')['reason']);
        $this->assertSame('bad_json', Webhook::verify('nope', $this->signed('nope'), self::SECRET)['reason']);
    }

    public function testTagsFallBackWhenTheServerSendsNone(): void
    {
        $batch = ['event' => 'batch', 'events' => [
            ['event' => 'entry.updated', 'data' => ['type' => 'page', 'documentId' => 'd1', 'paths' => ['/sobre', '/en/about']]],
            ['event' => 'menu.updated', 'data' => []],
            ['event' => 'taxonomy.updated', 'data' => ['taxonomy' => 'category']],
            ['event' => 'asset.updated', 'data' => ['id' => 'a1']],
            ['event' => 'entry.updated', 'data' => ['paths' => ['/sobre']]],
        ]];
        $this->assertSame(['type:page', 'entry:d1', 'menus', 'taxonomy:category', 'asset:a1'], Webhook::tagsOf($batch));
        $this->assertSame(['/sobre', '/en/about'], Webhook::pathsOf($batch));
    }

    public function testBuildStatusBodyAndSignatureMatchBuildMjs(): void
    {
        $sent = [];
        $status = new BuildStatus('http://cms.test', 'demo', 'wh1', self::SECRET, 'https://site.test', static function (string $url, array $headers, string $body) use (&$sent): int {
            $sent[] = [$url, $headers, $body];
            return 200;
        });
        $this->assertTrue($status->report('building'));
        $this->assertTrue($status->report('success', 'Cache recriado: 3 páginas em 1s'));
        $this->assertSame('http://cms.test/api/demo/webhooks/wh1/status', $sent[0][0]);
        $this->assertSame('{"status":"building"}', $sent[0][2]);
        $this->assertSame('{"status":"success","message":"Cache recriado: 3 páginas em 1s","url":"https://site.test"}', $sent[1][2]);
        $headers = $sent[1][1];
        $this->assertSame(hash_hmac('sha256', $headers['x-cms-timestamp'] . '.' . $sent[1][2], self::SECRET), $headers['x-cms-signature']);
        $this->assertSame('{"status":"failed","message":"x"}', $status->body('failed', 'x'));
        $this->assertFalse((new BuildStatus('http://cms.test', 'demo', '', self::SECRET, ''))->report('building'), 'no webhook id, nothing sent');
    }
}
