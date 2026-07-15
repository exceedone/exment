<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\Http;

/**
 * GĐ2: webhook LINE. Route công khai admin/line/webhook, verify chữ ký X-Line-Signature.
 *
 * Chữ ký ký bằng channel secret; ta set secret qua config('exment.line.channel_secret')
 * (System::system_line_channel_secret đọc từ config này). reply() gọi ra LINE được
 * chặn bằng Http::fake() -> không gọi API thật.
 */
class LineWebhookTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    public const SECRET = 'webhook-test-secret';
    public const WEBHOOK_URL = 'admin/line/webhook';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config(['exment.line.channel_secret' => static::SECRET]);
        config(['exment.line.channel_access_token' => 'webhook-test-token']);
        // mọi request ra api.line.me trả 200 rỗng -> reply()/push() không chạm mạng thật
        Http::fake(['api.line.me/*' => Http::response('{}', 200)]);
    }

    /** POST webhook với body + chữ ký hợp lệ (ký bằng SECRET). */
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

    // -------------------------------------------------- chữ ký

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
        // LINE luôn cần 200 kể cả events rỗng
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

    // -------------------------------------------------- liên kết qua tin nhắn

    public function testLinkMessageLinksAccount()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        // sinh mã cho user1
        $code = LineAccountLink::forUser($userId)->generateCode();
        $lineUserId = 'Ulinkme';

        $response = $this->postWebhook(['events' => [
            $this->messageEvent('LINK ' . $code, $lineUserId),
        ]]);

        $response->assertStatus(200);

        $link = LineAccountLink::where('user_id', $userId)->first();
        $this->assertEquals($lineUserId, $link->line_user_id, 'line_user_id chưa được lưu.');
        $this->assertNull($link->line_link_code, 'Mã 1 lần phải bị xóa sau khi liên kết.');
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
        $this->assertNull($link->line_user_id, 'Mã sai mà vẫn liên kết.');
    }

    public function testAlreadyLinkedLineCannotStealAnotherAccount()
    {
        $user1 = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $user2 = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $lineUserId = 'Ualreadylinked';

        // LINE này đã gắn user1
        LineAccountLink::forUser($user1)->markLinked($lineUserId);
        // user2 sinh mã và LINE (đã gắn user1) thử liên kết sang user2
        $code2 = LineAccountLink::forUser($user2)->generateCode();

        $this->postWebhook(['events' => [
            $this->messageEvent('LINK ' . $code2, $lineUserId),
        ]])->assertStatus(200);

        // user2 vẫn chưa liên kết; user1 giữ nguyên
        $this->assertNull(LineAccountLink::where('user_id', $user2)->first()->line_user_id);
        $this->assertEquals($lineUserId, LineAccountLink::where('user_id', $user1)->first()->line_user_id);
    }
}
