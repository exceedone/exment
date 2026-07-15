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
 * GĐ1: LineMessagingClient — push / reply / verify chữ ký webhook.
 *
 * Kế thừa UnitTestBase (boot Laravel) vì constructor đọc config('exment.line.*').
 * HTTP được inject bằng Guzzle MockHandler -> không gọi API LINE thật.
 */
class LineMessagingClientTest extends UnitTestBase
{
    public const TOKEN  = 'test-channel-token';
    public const SECRET = 'test-channel-secret';

    /** @var array<int, array> lịch sử request Guzzle đã gửi */
    protected $history = [];

    /**
     * Dựng client với 1 response giả lập; mọi request được ghi vào $this->history.
     *
     * @param int $status HTTP status LINE trả về
     * @param string $body body LINE trả về
     */
    protected function makeClient(int $status = 200, string $body = '{}'): LineMessagingClient
    {
        $this->history = [];

        $stack = HandlerStack::create(new MockHandler([new Response($status, [], $body)]));
        $stack->push(Middleware::history($this->history));

        $http = new Client(['handler' => $stack]);

        return new LineMessagingClient(static::TOKEN, static::SECRET, $http);
    }

    /** Request cuối cùng đã gửi. */
    protected function lastRequest(): \Psr\Http\Message\RequestInterface
    {
        $this->assertNotEmpty($this->history, 'Không có request nào được gửi.');
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

        // không ném exception: http_errors = false -> trả về kết quả để LineSendJob ghi log
        $this->assertFalse($res['ok']);
        $this->assertEquals(401, $res['status']);
        $this->assertEquals($raw, $res['raw']);
        $this->assertEquals('Authentication failed', $res['body']['message']);
    }

    /** Cho phép truyền 1 message đơn lẻ (có key 'type') thay vì mảng nhiều message. */
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

        // đúng cách LINE ký: HMAC-SHA256 body bằng channel secret, rồi base64
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

        // body bị sửa sau khi ký -> chữ ký không còn khớp
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
