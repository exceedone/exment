<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Services\Line\LineAccountLinker;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * GĐ2: LineAccountLinker — sinh mã 1 lần, deep link, và khớp "LINK <mã>".
 * Chạm DB (bảng line_account_links) nên là Feature test.
 */
class LineAccountLinkerTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    /** @var LineAccountLinker */
    protected $linker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->linker = new LineAccountLinker();
    }

    protected function user1(): int
    {
        return (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
    }

    // -------------------------------------------------- sinh mã

    public function testGenerateCodeCreatesUnlinkedRecord()
    {
        $code = $this->linker->generateCodeForUser($this->user1());

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4,12}$/', $code);

        $link = LineAccountLink::where('user_id', $this->user1())->first();
        $this->assertEquals($code, $link->line_link_code);
        $this->assertNull($link->line_user_id, 'Mã mới sinh chưa được gắn LINE nào.');
        $this->assertFalse($link->isLinked());
    }

    public function testGenerateCodeIsIdempotentPerUser()
    {
        // sinh mã 2 lần cho cùng user -> vẫn 1 bản ghi (user_id unique), mã mới ghi đè
        $first  = $this->linker->generateCodeForUser($this->user1());
        $second = $this->linker->generateCodeForUser($this->user1());

        $this->assertNotEquals($first, $second);
        $this->assertEquals(1, LineAccountLink::where('user_id', $this->user1())->count());
        $this->assertEquals($second, LineAccountLink::where('user_id', $this->user1())->first()->line_link_code);
    }

    // -------------------------------------------------- deep link

    public function testDeepLinkContainsEncodedLinkCommand()
    {
        $url = $this->linker->deepLink('ABC123');

        $this->assertStringContainsString('line.me/R/oaMessage', $url);
        // "LINK ABC123" phải được url-encode (dấu cách -> %20)
        $this->assertStringContainsString(rawurlencode('LINK ABC123'), $url);
    }

    // -------------------------------------------------- handleMessage

    public function testHandleMessageLinksOnValidCode()
    {
        $code = $this->linker->generateCodeForUser($this->user1());
        $lineUserId = 'Uhandlelink';

        $link = $this->linker->handleMessage('LINK ' . $code, $lineUserId);

        $this->assertNotNull($link, 'Khớp mã đúng phải trả về bản ghi.');
        $this->assertEquals($this->user1(), $link->user_id);
        $this->assertEquals($lineUserId, $link->line_user_id);
        $this->assertTrue($link->fresh()->isLinked());
    }

    public function testHandleMessageIsCaseInsensitiveForCommand()
    {
        $code = $this->linker->generateCodeForUser($this->user1());

        // "link" thường vẫn khớp (regex /i)
        $link = $this->linker->handleMessage('link ' . strtolower($code), 'Ulowercase');

        $this->assertNotNull($link);
        $this->assertEquals('Ulowercase', $link->line_user_id);
    }

    public function testHandleMessageReturnsNullForNonLinkText()
    {
        $this->linker->generateCodeForUser($this->user1());

        $this->assertNull($this->linker->handleMessage('xin chào', 'Uchat'));
        $this->assertNull($this->linker->handleMessage('LINK', 'Uchat'));       // thiếu mã
        $this->assertNull($this->linker->handleMessage('', 'Uchat'));
    }

    public function testHandleMessageReturnsNullWhenLineUserIdMissing()
    {
        $code = $this->linker->generateCodeForUser($this->user1());

        $this->assertNull($this->linker->handleMessage('LINK ' . $code, null));
        $this->assertNull($this->linker->handleMessage('LINK ' . $code, ''));
    }

    public function testHandleMessageRejectsWhenLineAlreadyBoundToAnotherUser()
    {
        $user1 = $this->user1();
        $user2 = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $lineUserId = 'Ubound';

        // LINE đã gắn user1
        LineAccountLink::forUser($user1)->markLinked($lineUserId);
        // user2 sinh mã, LINE (đã gắn user1) thử liên kết sang user2 -> từ chối
        $code2 = $this->linker->generateCodeForUser($user2);

        $result = $this->linker->handleMessage('LINK ' . $code2, $lineUserId);

        $this->assertNull($result, '1 LINE không được gắn 2 tài khoản.');
        $this->assertNull(LineAccountLink::where('user_id', $user2)->first()->line_user_id);
    }

    public function testHandleMessageAllowsRelinkingSameUser()
    {
        $user1 = $this->user1();
        $lineUserId = 'Usameuser';

        LineAccountLink::forUser($user1)->markLinked($lineUserId);
        // cùng user1 sinh mã mới rồi liên kết lại bằng chính LINE đó -> cho phép
        $code = $this->linker->generateCodeForUser($user1);

        $result = $this->linker->handleMessage('LINK ' . $code, $lineUserId);

        $this->assertNotNull($result);
        $this->assertEquals($user1, $result->user_id);
        $this->assertEquals($lineUserId, $result->line_user_id);
    }
}
