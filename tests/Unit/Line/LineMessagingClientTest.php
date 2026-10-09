<?php

namespace Exceedone\Exment\Tests\Unit\Line;

use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Tests\Unit\UnitTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;

class LineMessagingClientTest extends UnitTestBase
{
    public const TOKEN  = 'test-channel-token';
    public const SECRET = 'test-channel-secret';

    /** @var array<int, array> */
    protected $history = [];

    /**
     * @param int $status
     * @param string $body
     */
    protected function makeClient(int $status = 200, string $body = '{}'): LineMessagingClient
    {
        $this->history = [];

        $stack = HandlerStack::create(new MockHandler([new Response($status, [], $body)]));
        $stack->push(Middleware::history($this->history));

        $http = new Client(['handler' => $stack]);

        return new LineMessagingClient(static::TOKEN, static::SECRET, $http);
    }

    protected function lastRequest(): \Psr\Http\Message\RequestInterface
    {
        $this->assertNotEmpty($this->history, 'No request was sent.');
        return $this->history[count($this->history) - 1]['request'];
    }

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

        $this->assertFalse($res['ok']);
        $this->assertEquals(401, $res['status']);
        $this->assertEquals($raw, $res['raw']);
        $this->assertEquals('Authentication failed', $res['body']['message']);
    }

    public function test_push_accepts_a_single_message_and_wraps_it(): void
    {
        $client = $this->makeClient();

        $client->push('Uabc', LineMessagingClient::text('một tin'));

        $sent = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertEquals([['type' => 'text', 'text' => 'một tin']], $sent['messages']);
    }

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

    public function test_reply_failure_is_logged_as_warning(): void
    {
        Log::spy();
        $client = $this->makeClient(401, '{"message":"Authentication failed"}');

        $res = $client->reply('reply-token-xyz', [LineMessagingClient::text('x')]);

        $this->assertFalse($res['ok']);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function ($message, $context = []) {
                return $message === 'LINE reply failed' && ($context['status'] ?? null) === 401;
            });
    }

    public function test_verifySignature_accepts_signature_computed_with_channel_secret(): void
    {
        $client = $this->makeClient();
        $body = '{"events":[{"type":"message"}]}';

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

        $this->assertFalse($client->verifySignature('{"events":[{"type":"message"}]}', $signature));
    }

    public function test_verifySignature_rejects_everything_when_secret_is_empty(): void
    {
        $client = new LineMessagingClient(static::TOKEN, '', new Client(['handler' => HandlerStack::create(new MockHandler([]))]));
        $body = '{"events":[]}';
        $signedWithEmptyKey = base64_encode(hash_hmac('sha256', $body, '', true));

        $this->assertFalse($client->verifySignature($body, $signedWithEmptyKey));
    }

    public function test_verifySignature_rejects_missing_signature(): void
    {
        $client = $this->makeClient();
        $body = '{"events":[]}';

        $this->assertFalse($client->verifySignature($body, null));
        $this->assertFalse($client->verifySignature($body, ''));
    }
}
