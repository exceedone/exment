<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Services\Line\LineAccountLinker;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Phase 2: LineAccountLinker — one-time code generation, deep link, and matching "LINK <code>".
 * Touches the DB (line_account_links table), so this is a Feature test.
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

    // -------------------------------------------------- code generation

    public function testGenerateCodeCreatesUnlinkedRecord()
    {
        $code = $this->linker->generateCodeForUser($this->user1());

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4,12}$/', $code);

        $link = LineAccountLink::where('user_id', $this->user1())->first();
        $this->assertEquals($code, $link->line_link_code);
        $this->assertNull($link->line_user_id, 'A newly generated code is not yet bound to any LINE account.');
        $this->assertFalse($link->isLinked());
    }

    public function testGenerateCodeIsIdempotentPerUser()
    {
        // Generating a code twice for the same user still yields one record (user_id is unique); the new code overwrites the old.
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
        // "LINK ABC123" must be url-encoded (space -> %20)
        $this->assertStringContainsString(rawurlencode('LINK ABC123'), $url);
    }

    // -------------------------------------------------- handleMessage

    public function testHandleMessageLinksOnValidCode()
    {
        $code = $this->linker->generateCodeForUser($this->user1());
        $lineUserId = 'Uhandlelink';

        $link = $this->linker->handleMessage('LINK ' . $code, $lineUserId);

        $this->assertNotNull($link, 'Matching a valid code must return a record.');
        $this->assertEquals($this->user1(), $link->user_id);
        $this->assertEquals($lineUserId, $link->line_user_id);
        $this->assertTrue($link->fresh()->isLinked());
    }

    public function testHandleMessageIsCaseInsensitiveForCommand()
    {
        $code = $this->linker->generateCodeForUser($this->user1());

        // lowercase "link" still matches (regex uses /i)
        $link = $this->linker->handleMessage('link ' . strtolower($code), 'Ulowercase');

        $this->assertNotNull($link);
        $this->assertEquals('Ulowercase', $link->line_user_id);
    }

    public function testHandleMessageReturnsNullForNonLinkText()
    {
        $this->linker->generateCodeForUser($this->user1());

        $this->assertNull($this->linker->handleMessage('xin chào', 'Uchat'));
        $this->assertNull($this->linker->handleMessage('LINK', 'Uchat'));       // missing code
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

        // LINE is already bound to user1
        LineAccountLink::forUser($user1)->markLinked($lineUserId);
        // user2 generates a code; the LINE (already bound to user1) tries to link to user2 -> rejected
        $code2 = $this->linker->generateCodeForUser($user2);

        $result = $this->linker->handleMessage('LINK ' . $code2, $lineUserId);

        $this->assertNull($result, 'A single LINE account cannot be bound to two users.');
        $this->assertNull(LineAccountLink::where('user_id', $user2)->first()->line_user_id);
    }

    public function testHandleMessageAllowsRelinkingSameUser()
    {
        $user1 = $this->user1();
        $lineUserId = 'Usameuser';

        LineAccountLink::forUser($user1)->markLinked($lineUserId);
        // user1 generates a new code and re-links with the same LINE account -> allowed
        $code = $this->linker->generateCodeForUser($user1);

        $result = $this->linker->handleMessage('LINK ' . $code, $lineUserId);

        $this->assertNotNull($result);
        $this->assertEquals($user1, $result->user_id);
        $this->assertEquals($lineUserId, $result->line_user_id);
    }
}
