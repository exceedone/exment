<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Carbon\Carbon;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckSender;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Notification;
use Psr\Http\Message\RequestInterface;

/**
 * What happens while a send runs: the LINE messages go out for real (sync queue, faked API),
 * so the test can look at the event between two of them.
 */
class SafetyCheckSendProgressTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;
    use SafetyCheckTestHelpers;

    /** @var \Closure|null called on every LINE push with the number of pushes so far */
    protected $onPush;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        SafetyCheckInstaller::ensureAll();
        Notification::fake();
        config(['exment.line.channel_access_token' => 'progress-test-token']);

        $pushes = 0;
        $stack = HandlerStack::create(function (RequestInterface $request) use (&$pushes) {
            if ($this->onPush && $request->getUri()->getPath() === '/v2/bot/message/push') {
                ($this->onPush)(++$pushes);
            }
            return Create::promiseFor(new GuzzleResponse(200, [], '{}'));
        });
        $guzzle = new Client(['handler' => $stack, 'base_uri' => 'https://api.line.me']);
        $this->app->bind(LineMessagingClient::class, function () use ($guzzle) {
            return new LineMessagingClient('progress-test-token', null, $guzzle);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testFirstSendShowsHowFarItGotWhileRunning(): void
    {
        $event = $this->createEvent();
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER2);
        $seen = [];
        $this->onPush = function () use ($event, &$seen) {
            $fresh = $this->freshEvent($event->id);
            $seen[] = [(int) $fresh->getValue('sent_count'), (int) $fresh->getValue('target_count')];
            Carbon::setTestNow(now()->addSeconds(SafetyCheckSender::CHECK_EVERY_SECONDS));
        };

        $result = SafetyCheckSender::send($event);

        $this->assertCount(2, $seen, 'Fixture: two linked users.');
        $this->assertSame([0, $result['target']], $seen[0], 'The target shows as soon as the messages start.');
        $this->assertGreaterThan(0, $seen[1][0], 'Then how many were notified so far: a count that stops moving tells a dead send.');
        $this->assertLessThan($result['target'], $seen[1][0]);
    }

    public function testClosingStopsTheRemainingMessages(): void
    {
        $event = $this->createEvent();
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->onPush = function (int $pushes) use ($event) {
            if ($pushes === 1) {
                $this->freshEvent($event->id)->setValue(['event_status' => 'closed'])->save();
                Carbon::setTestNow(now()->addSeconds(SafetyCheckSender::CHECK_EVERY_SECONDS));
            }
        };

        $result = SafetyCheckSender::send($event);

        $this->assertSame(1, $result['line'], 'No LINE message once the 締切 was seen.');
        $fresh = $this->freshEvent($event->id);
        $this->assertEquals('closed', $fresh->getValue('event_status'));
        $this->assertLessThan($result['target'], (int) $fresh->getValue('sent_count'), 'The count says how far it got.');
    }
}
