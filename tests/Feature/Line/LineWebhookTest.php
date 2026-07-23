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

/**
 * Phase 2: LINE webhook. Public route admin/line/webhook, verifies the X-Line-Signature.
 *
 * The signature is signed with the channel secret, which we set via config('exment.line.channel_secret')
 * (System::system_line_channel_secret reads from this config). Calls out to LINE via reply()
 * are blocked with Http::fake() so no real API request is made.
 */
class LineWebhookTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    public const SECRET = 'webhook-test-secret';
    public const WEBHOOK_URL = 'admin/line/webhook';

    /** @var \ArrayObject Guzzle transactions captured from the mocked LINE transport. */
    protected $lineHistory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config(['exment.line.channel_secret' => static::SECRET]);
        config(['exment.line.channel_access_token' => 'webhook-test-token']);

        // reply()/push() use Guzzle directly (not the Http facade), so bind a LineMessagingClient
        // whose transport is mocked: keeps the suite hermetic and records every outgoing request.
        $this->lineHistory = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 20, new GuzzleResponse(200, [], '{}'))));
        $stack->push(Middleware::history($this->lineHistory));
        $guzzle = new Client(['handler' => $stack, 'base_uri' => 'https://api.line.me']);
        $this->app->bind(LineMessagingClient::class, function () use ($guzzle) {
            return new LineMessagingClient(null, static::SECRET, $guzzle);
        });

        Http::fake(['api.line.me/*' => Http::response('{}', 200)]);
    }

    /** Paths of the LINE requests captured so far (e.g. '/v2/bot/message/reply'). */
    protected function lineRequestPaths(): array
    {
        $paths = [];
        foreach ($this->lineHistory as $tx) {
            $paths[] = $tx['request']->getUri()->getPath();
        }
        return $paths;
    }

    /** POST to the webhook with a body and a valid signature (signed with SECRET). */
    protected function postWebhook(array $payload)
    {
        $body = json_encode($payload);
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

    protected function messageEvent(string $text, string $lineUserId = 'Uwebhooktest', string $replyToken = 'rt-1'): array
    {
        return [
            'type' => 'message',
            'replyToken' => $replyToken,
            'source' => ['userId' => $lineUserId],
            'message' => ['type' => 'text', 'text' => $text],
        ];
    }

    // -------------------------------------------------- signature

    public function testRejectsRequestWithoutSignature()
    {
        $response = $this->call(
            'POST',
            static::WEBHOOK_URL,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['events' => []])
        );

        $response->assertStatus(400);
    }

    public function testRejectsForgedSignature()
    {
        $body = json_encode(['events' => []]);
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

    public function testAcceptsValidSignatureAndReturns200()
    {
        // LINE always requires a 200, even when events is empty
        $this->postWebhook(['events' => []])->assertStatus(200);
    }

    // -------------------------------------------------- follow

    public function testFollowEventReturns200()
    {
        $response = $this->postWebhook(['events' => [[
            'type' => 'follow',
            'replyToken' => 'rt-follow',
            'source' => ['userId' => 'Ufollowtest'],
        ]]]);

        $response->assertStatus(200);
    }

    /**
     * A follow event must not trigger any reply. Linking is driven from the web QR/deep link
     * (which pre-fills the real "LINK <code>"), so a follow greeting would only be redundant.
     * This also covers the unblock/re-add case, where a follow fires for an already-linked user.
     */
    public function testFollowSendsNoReply()
    {
        // brand-new follower
        $this->postWebhook(['events' => [[
            'type' => 'follow',
            'replyToken' => 'rt-follow-new',
            'source' => ['userId' => 'Ufollownew'],
        ]]])->assertStatus(200);

        // already-linked user unblocking / re-adding the OA
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        LineAccountLink::forUser($userId)->markLinked('Urefollow');
        $this->postWebhook(['events' => [[
            'type' => 'follow',
            'replyToken' => 'rt-refollow',
            'source' => ['userId' => 'Urefollow'],
        ]]])->assertStatus(200);

        $this->assertNotContains('/v2/bot/message/reply', $this->lineRequestPaths(), 'A follow event must not send any reply.');
    }

    /**
     * Positive control: an unlinked user texting anything gets the syntax guidance, so a reply IS
     * sent. Proves the mocked transport actually records reply requests, keeping the negative
     * assertion in testFollowSendsNoReply honest.
     */
    public function testTextMessageSendsReply()
    {
        $this->postWebhook(['events' => [
            $this->messageEvent('hello', 'Uplaintext'),
        ]])->assertStatus(200);

        $this->assertContains('/v2/bot/message/reply', $this->lineRequestPaths());
    }

    // -------------------------------------------------- account linking via message

    public function testLinkMessageLinksAccount()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        // Generate a code for user1
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

    public function testWrongCodeDoesNotLink()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        LineAccountLink::forUser($userId)->generateCode();

        $this->postWebhook(['events' => [
            $this->messageEvent('LINK ZZZZZZ', 'Uwrongcode'),
        ]])->assertStatus(200);

        $link = LineAccountLink::where('user_id', $userId)->first();
        $this->assertNull($link->line_user_id, 'Linked despite a wrong code.');
    }

    public function testAlreadyLinkedLineCannotStealAnotherAccount()
    {
        $user1 = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $user2 = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $lineUserId = 'Ualreadylinked';

        // This LINE account is already linked to user1
        LineAccountLink::forUser($user1)->markLinked($lineUserId);
        // user2 generates a code, and the LINE account (already linked to user1) tries to link to user2
        $code2 = LineAccountLink::forUser($user2)->generateCode();

        $this->postWebhook(['events' => [
            $this->messageEvent('LINK ' . $code2, $lineUserId),
        ]])->assertStatus(200);

        // user2 remains unlinked; user1 stays unchanged
        $this->assertNull(LineAccountLink::where('user_id', $user2)->first()->line_user_id);
        $this->assertEquals($lineUserId, LineAccountLink::where('user_id', $user1)->first()->line_user_id);
    }
}
