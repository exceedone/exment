<?php

namespace Exceedone\Exment\Tests\Unit\Line;

use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Tests\Unit\UnitTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Phase 1: LineMessagingClient — push / reply / webhook signature verification.
 *
 * Extends UnitTestBase (boots Laravel) because the constructor reads config('exment.line.*').
 * HTTP is injected via a Guzzle MockHandler so the real LINE API is never called.
 */
class LineMessagingClientTest extends UnitTestBase
{
    public const TOKEN  = 'test-channel-token';
    public const SECRET = 'test-channel-secret';

    /** @var array<int, array> History of Guzzle requests that were sent. */
    protected $history = [];

    /**
     * Build a client with a single mocked response; every request is recorded into $this->history.
     *
     * @param int $status HTTP status returned by LINE
     * @param string $body response body returned by LINE
     */
    protected function makeClient(int $status = 200, string $body = '{}'): LineMessagingClient
    {
        $this->history = [];

        $stack = HandlerStack::create(new MockHandler([new Response($status, [], $body)]));
        $stack->push(Middleware::history($this->history));

        $http = new Client(['handler' => $stack]);

        return new LineMessagingClient(static::TOKEN, static::SECRET, $http);
    }

    /** The last request that was sent. */
    protected function lastRequest(): \Psr\Http\Message\RequestInterface
    {
        $this->assertNotEmpty($this->history, 'No request was sent.');
        return $this->history[count($this->history) - 1]['request'];
    }

    // ---------------------------------------------------------------- push

    public function test_push_sends_to_push_endpoint_with_bearer_token(): void
    {
        $client = $this->makeClient(200, '{}');

        $res = $client->push('Uabc', [LineMessagingClient::text('xin chào')]);

        $request = $this->lastRequest();
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('/v2/bot/message/push', $request->getUri()->getPath());
        $this->assertEquals('Bearer ' . static::TOKEN, $request->getHeaderLine('Authorization'));
        $this->assertEquals('application/json', $request->getHeaderLine('Content-Type'));

        $sent = json_decode((string) $request->getBody(), true);
        $this->assertEquals('Uabc', $sent['to']);
        $this->assertEquals([['type' => 'text', 'text' => 'xin chào']], $sent['messages']);

        $this->assertTrue($res['ok']);
        $this->assertEquals(200, $res['status']);
    }

    public function test_push_reports_failure_and_keeps_error_body(): void
    {
        $raw = '{"message":"Authentication failed"}';
        $client = $this->makeClient(401, $raw);

        $res = $client->push('Uabc', [LineMessagingClient::text('xin chào')]);

        // No exception thrown: http_errors = false, so the result is returned for LineSendJob to log.
        $this->assertFalse($res['ok']);
        $this->assertEquals(401, $res['status']);
        $this->assertEquals($raw, $res['raw']);
        $this->assertEquals('Authentication failed', $res['body']['message']);
    }

    /** Allows passing a single message (with a 'type' key) instead of an array of messages. */
    public function test_push_accepts_a_single_message_and_wraps_it(): void
    {
        $client = $this->makeClient();

        $client->push('Uabc', LineMessagingClient::text('một tin'));

        $sent = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertEquals([['type' => 'text', 'text' => 'một tin']], $sent['messages']);
    }

    // --------------------------------------------------------------- reply

    public function test_reply_sends_reply_token_to_reply_endpoint(): void
    {
        $client = $this->makeClient();

        $res = $client->reply('reply-token-xyz', [LineMessagingClient::text('đã nhận')]);

        $request = $this->lastRequest();
        $this->assertEquals('/v2/bot/message/reply', $request->getUri()->getPath());

        $sent = json_decode((string) $request->getBody(), true);
        $this->assertEquals('reply-token-xyz', $sent['replyToken']);
        $this->assertEquals([['type' => 'text', 'text' => 'đã nhận']], $sent['messages']);
        $this->assertArrayNotHasKey('to', $sent);

        $this->assertTrue($res['ok']);
    }

    // ----------------------------------------------------- verifySignature

    public function test_verifySignature_accepts_signature_computed_with_channel_secret(): void
    {
        $client = $this->makeClient();
        $body = '{"events":[{"type":"message"}]}';

        // Matches how LINE signs: HMAC-SHA256 of the body with the channel secret, then base64.
        $signature = base64_encode(hash_hmac('sha256', $body, static::SECRET, true));

        $this->assertTrue($client->verifySignature($body, $signature));
    }

    public function test_verifySignature_rejects_signature_from_another_secret(): void
    {
        $client = $this->makeClient();
        $body = '{"events":[]}';

        $forged = base64_encode(hash_hmac('sha256', $body, 'secret-cua-ke-gia-mao', true));

        $this->assertFalse($client->verifySignature($body, $forged));
    }

    public function test_verifySignature_rejects_tampered_body(): void
    {
        $client = $this->makeClient();
        $signature = base64_encode(hash_hmac('sha256', '{"events":[]}', static::SECRET, true));

        // Body modified after signing, so the signature no longer matches.
        $this->assertFalse($client->verifySignature('{"events":[{"type":"message"}]}', $signature));
    }

    public function test_verifySignature_rejects_missing_signature(): void
    {
        $client = $this->makeClient();
        $body = '{"events":[]}';

        $this->assertFalse($client->verifySignature($body, null));
        $this->assertFalse($client->verifySignature($body, ''));
    }
}
