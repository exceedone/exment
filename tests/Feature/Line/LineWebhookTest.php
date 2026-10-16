<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Http;

class LineWebhookTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    public const SECRET = 'webhook-test-secret';
    public const WEBHOOK_URL = 'admin/line/webhook';

    /** @var \ArrayObject<int, array<string, mixed>> Guzzle history: request, response per LINE API call */
    protected $lineHistory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config(['exment.line.channel_secret' => static::SECRET]);
        config(['exment.line.channel_access_token' => 'webhook-test-token']);

        $this->lineHistory = new \ArrayObject();
        $history = $this->lineHistory; // same object; a local keeps the by-reference parameter off the typed property
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 20, new GuzzleResponse(200, [], '{}'))));
        $stack->push(Middleware::history($history));
        $guzzle = new Client(['handler' => $stack, 'base_uri' => 'https://api.line.me']);
        $this->app->bind(LineMessagingClient::class, function () use ($guzzle) {
            return new LineMessagingClient(null, static::SECRET, $guzzle);
        });

        Http::fake(['api.line.me/*' => Http::response('{}', 200)]);
    }

    /**
     * @return array<int, string>
     */
    protected function lineRequestPaths(): array
    {
        $paths = [];
        foreach ($this->lineHistory as $tx) {
            $paths[] = $tx['request']->getUri()->getPath();
        }
        return $paths;
    }

    /**
     * @param array<string, mixed> $payload
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postWebhook(array $payload)
    {
        $body = (string) json_encode($payload);
        $signature = base64_encode(hash_hmac('sha256', $body, static::SECRET, true));

        return $this->call(
            'POST',
            static::WEBHOOK_URL,
            [],
            [],
            [],
            ['HTTP_X_LINE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $body
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function messageEvent(string $text, string $lineUserId = 'Uwebhooktest', string $replyToken = 'rt-1'): array
    {
        return [
            'type' => 'message',
            'replyToken' => $replyToken,
            'source' => ['userId' => $lineUserId],
            'message' => ['type' => 'text', 'text' => $text],
        ];
    }

    public function testRejectsRequestWithoutSignature(): void
    {
        $response = $this->call(
            'POST',
            static::WEBHOOK_URL,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['events' => []])
        );

        $response->assertStatus(400);
    }

    public function testRejectsForgedSignature(): void
    {
        $body = (string) json_encode(['events' => []]);
        $forged = base64_encode(hash_hmac('sha256', $body, 'secret-cua-ke-gia-mao', true));

        $response = $this->call(
            'POST',
            static::WEBHOOK_URL,
            [],
            [],
            [],
            ['HTTP_X_LINE_SIGNATURE' => $forged, 'CONTENT_TYPE' => 'application/json'],
            $body
        );

        $response->assertStatus(400);
    }

    public function testAcceptsValidSignatureAndReturns200(): void
    {
        $this->postWebhook(['events' => []])->assertStatus(200);
    }

    public function testFollowEventReturns200(): void
    {
        $response = $this->postWebhook(['events' => [[
            'type' => 'follow',
            'replyToken' => 'rt-follow',
            'source' => ['userId' => 'Ufollowtest'],
        ]]]);

        $response->assertStatus(200);
    }

    public function testFollowSendsNoReply(): void
    {
        $this->postWebhook(['events' => [[
            'type' => 'follow',
            'replyToken' => 'rt-follow-new',
            'source' => ['userId' => 'Ufollownew'],
        ]]])->assertStatus(200);

        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        LineAccountLink::forUser($userId)->markLinked('Urefollow');
        $this->postWebhook(['events' => [[
            'type' => 'follow',
            'replyToken' => 'rt-refollow',
            'source' => ['userId' => 'Urefollow'],
        ]]])->assertStatus(200);

        $this->assertNotContains('/v2/bot/message/reply', $this->lineRequestPaths(), 'A follow event must not send any reply.');
    }

    public function testTextMessageSendsReply(): void
    {
        $this->postWebhook(['events' => [
            $this->messageEvent('hello', 'Uplaintext'),
        ]])->assertStatus(200);

        $this->assertContains('/v2/bot/message/reply', $this->lineRequestPaths());
    }

    public function testLinkMessageLinksAccount(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $code = LineAccountLink::forUser($userId)->generateCode();
        $lineUserId = 'Ulinkme';

        $response = $this->postWebhook(['events' => [
            $this->messageEvent('LINK ' . $code, $lineUserId),
        ]]);

        $response->assertStatus(200);

        $link = LineAccountLink::where('user_id', $userId)->first();
        $this->assertEquals($lineUserId, $link->line_user_id, 'line_user_id was not saved.');
        $this->assertNull($link->line_link_code, 'The one-time code must be cleared after linking.');
        $this->assertNotNull($link->linked_at);
    }

    public function testWrongCodeDoesNotLink(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        LineAccountLink::forUser($userId)->generateCode();

        $this->postWebhook(['events' => [
            $this->messageEvent('LINK ZZZZZZ', 'Uwrongcode'),
        ]])->assertStatus(200);

        $link = LineAccountLink::where('user_id', $userId)->first();
        $this->assertNull($link->line_user_id, 'Linked despite a wrong code.');
    }

    public function testAlreadyLinkedLineCannotStealAnotherAccount(): void
    {
        $user1 = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $user2 = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $lineUserId = 'Ualreadylinked';

        LineAccountLink::forUser($user1)->markLinked($lineUserId);
        $code2 = LineAccountLink::forUser($user2)->generateCode();

        $this->postWebhook(['events' => [
            $this->messageEvent('LINK ' . $code2, $lineUserId),
        ]])->assertStatus(200);

        $this->assertNull(LineAccountLink::where('user_id', $user2)->first()->line_user_id);
        $this->assertEquals($lineUserId, LineAccountLink::where('user_id', $user1)->first()->line_user_id);
    }
}
