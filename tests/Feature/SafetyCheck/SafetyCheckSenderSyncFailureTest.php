<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckSender;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Notification;

class SafetyCheckSenderSyncFailureTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;
    use SafetyCheckTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        SafetyCheckInstaller::ensureAll();
        Notification::fake();
        config(['exment.line.channel_access_token' => 'sync-failure-test-token']);
    }

    protected function bindClientReturning(int $status): void
    {
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 50, new GuzzleResponse($status, [], '{"message":"x"}'))));
        $guzzle = new Client(['handler' => $stack, 'base_uri' => 'https://api.line.me']);
        $this->app->bind(LineMessagingClient::class, function () use ($guzzle) {
            return new LineMessagingClient('sync-failure-test-token', null, $guzzle);
        });
    }

    protected function removeAllEmails(): void
    {
        $userTable = CustomTable::getEloquent('user');
        foreach ($userTable->getValueQuery()->get() as $user) {
            $user->setValue('email', null)->save();
        }
    }

    public function testRejectedPushIsNotCountedAsSent()
    {
        $this->bindClientReturning(401);
        $this->removeAllEmails();
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked('U_sync_fail_1');
        $event = $this->createEvent();

        $result = SafetyCheckSender::send($event);

        $this->assertEquals(0, $result['line'], 'A push LINE rejected must not count as a LINE delivery.');
        $fresh = CustomTable::getEloquent('safety_check_event')->getValueQuery()->find($event->id);
        $this->assertEquals(0, (int) $fresh->getValue('sent_count'));
        $this->assertEquals($this->totalUserCount(), (int) $fresh->getValue('target_count'), 'target_count is unaffected by delivery failures.');
    }

    public function testAcceptedPushIsCounted()
    {
        $this->bindClientReturning(200);
        $this->removeAllEmails();
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked('U_sync_ok_1');
        $event = $this->createEvent();

        $result = SafetyCheckSender::send($event);

        $this->assertEquals(1, $result['line']);
        $fresh = CustomTable::getEloquent('safety_check_event')->getValueQuery()->find($event->id);
        $this->assertEquals(1, (int) $fresh->getValue('sent_count'));
    }
}
