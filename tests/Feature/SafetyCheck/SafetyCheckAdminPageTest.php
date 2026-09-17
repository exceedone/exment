<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Exceedone\Exment\Controllers\SafetyCheckController;
use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\Bus;

class SafetyCheckAdminPageTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;
    use SafetyCheckTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        SafetyCheckInstaller::ensureAll();
        Bus::fake([LineSendJob::class]);

        $loginUser = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertNotNull($loginUser, 'Fixture: admin login user is required.');
        $this->be($loginUser, 'admin');
    }

    protected function freshEvent($id)
    {
        return CustomTable::getEloquent('safety_check_event')->getValueQuery()->find($id);
    }

    protected function latestEvent()
    {
        return CustomTable::getEloquent('safety_check_event')->getValueQuery()
            ->orderBy('id', 'desc')->first();
    }

    public function testIndexPageShowsMenuTitle()
    {
        $response = $this->get('admin/safety_check');

        $response->assertStatus(200);
        $response->assertSee(exmtrans('safety.menu_title'));
    }

    public function testSendCreatesManualEventAndDispatchesJobs()
    {
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked('U_admin_page_test_1');
        $userCount = $this->totalUserCount();

        $response = $this->post('admin/safety_check/send', [
            'title' => 'Admin created event',
            'trigger_type' => 'manual',
        ]);

        $response->assertStatus(302);

        $event = $this->latestEvent();
        $this->assertNotNull($event, 'A new safety_check_event row must have been created.');
        $this->assertEquals('Admin created event', $event->getValue('title'));
        $this->assertEquals('manual', $event->getValue('trigger_type'));
        $this->assertEquals('open', $event->getValue('event_status'));
        $this->assertNotNull($event->getValue('triggered_at'));

        $rows = $this->answerRows($event->id);
        $this->assertEquals($userCount, $rows->count(), 'An answer row must be pre-created for every user.');

        Bus::assertDispatchedTimes(LineSendJob::class, 1);
    }

    public function testSendDrillSetsTriggerType()
    {
        $response = $this->post('admin/safety_check/send', [
            'title' => 'Drill event',
            'trigger_type' => 'drill',
        ]);

        $response->assertStatus(302);

        $event = $this->latestEvent();
        $this->assertNotNull($event);
        $this->assertEquals('drill', $event->getValue('trigger_type'));
    }

    public function testResendThrottledWithinFiveMinutesDispatchesNoJob()
    {
        $linkedUserId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        LineAccountLink::forUser($linkedUserId)->markLinked('U_admin_page_test_resend');

        $seededResentAt = now()->subMinutes(2)->format('Y-m-d H:i:s');
        $event = $this->createEvent(['resent_at' => $seededResentAt]);
        $this->createAnswerRow($event->id, $linkedUserId);

        $response = $this->post("admin/safety_check/{$event->id}/resend");

        $response->assertStatus(302);
        Bus::assertNotDispatched(LineSendJob::class);

        $fresh = $this->freshEvent($event->id);
        $this->assertEquals($seededResentAt, $fresh->getValue('resent_at'), 'A throttled resend must not update resent_at.');
    }

    public function testResendThrottleSettingRespected()
    {
        System::safety_check_resend_throttle_minutes(1);
        System::clearCache();

        $linkedUserId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        LineAccountLink::forUser($linkedUserId)->markLinked('U_admin_page_throttle_setting');

        $event = $this->createEvent(['resent_at' => now()->subMinutes(2)->format('Y-m-d H:i:s')]);
        $this->createAnswerRow($event->id, $linkedUserId);

        $response = $this->post("admin/safety_check/{$event->id}/resend");

        $response->assertStatus(302);
        Bus::assertDispatchedTimes(LineSendJob::class, 1);

        $fresh = $this->freshEvent($event->id);
        $this->assertNotEquals(
            now()->subMinutes(2)->format('Y-m-d H:i:s'),
            $fresh->getValue('resent_at'),
            'A successful resend must refresh resent_at.'
        );
    }

    public function testPerPageSelectorControlsListSize()
    {
        $this->createEvent(['title' => 'zz_older_event_hidden']);
        $this->createEvent(['title' => 'zz_newer_event_visible']);

        $response = $this->get('admin/safety_check?per_page=1');

        $response->assertStatus(200);
        $response->assertSee('zz_newer_event_visible');
        $response->assertDontSee('zz_older_event_hidden');
    }

    public function testResendSendsOnlyToUnansweredUsersAndUpdatesResentAt()
    {
        $answeredUser = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $unansweredUser = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        LineAccountLink::forUser($answeredUser)->markLinked('U_admin_page_resend_answered');
        LineAccountLink::forUser($unansweredUser)->markLinked('U_admin_page_resend_unanswered');

        $event = $this->createEvent();
        $this->createAnswerRow($event->id, $answeredUser, ['answer_status' => 'safe']);
        $this->createAnswerRow($event->id, $unansweredUser);

        $this->assertNull($event->getValue('resent_at'));
        $rowCountBefore = $this->answerRows($event->id)->count();

        $response = $this->post("admin/safety_check/{$event->id}/resend");

        $response->assertStatus(302);
        Bus::assertDispatchedTimes(LineSendJob::class, 1);

        $jobsForUnanswered = Bus::dispatched(LineSendJob::class, function (LineSendJob $job) use ($unansweredUser) {
            return $job->getContext()['user_id'] === $unansweredUser;
        });
        $this->assertCount(1, $jobsForUnanswered, 'Only the still-unanswered linked user must get a job.');

        $rowsAfter = $this->answerRows($event->id);
        $this->assertEquals($this->totalUserCount(), $rowsAfter->count(), 'A resend must backfill missing answer rows for all users.');
        $this->assertGreaterThan($rowCountBefore, $rowsAfter->count());

        $fresh = $this->freshEvent($event->id);
        $this->assertNotNull($fresh->getValue('resent_at'), 'A successful resend must set resent_at.');
    }

    public function testResendOnClosedEventIsBlocked()
    {
        $linkedUserId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        LineAccountLink::forUser($linkedUserId)->markLinked('U_admin_page_resend_closed');

        $event = $this->createEvent(['event_status' => 'closed']);
        $this->createAnswerRow($event->id, $linkedUserId);

        $response = $this->post("admin/safety_check/{$event->id}/resend");

        $response->assertStatus(302);
        Bus::assertNotDispatched(LineSendJob::class);

        $fresh = $this->freshEvent($event->id);
        $this->assertEquals('closed', $fresh->getValue('event_status'));
        $this->assertNull($fresh->getValue('resent_at'), 'A blocked resend must not set resent_at.');
    }

    public function testSendWithEmptyTitleCreatesNoEventAndDispatchesNoJob()
    {
        $countBefore = CustomTable::getEloquent('safety_check_event')->getValueQuery()->count();

        $response = $this->post('admin/safety_check/send', [
            'title' => '',
            'trigger_type' => 'manual',
        ]);

        $response->assertStatus(302);

        $countAfter = CustomTable::getEloquent('safety_check_event')->getValueQuery()->count();
        $this->assertEquals($countBefore, $countAfter, 'An empty title must not create an event.');

        Bus::assertNotDispatched(LineSendJob::class);
    }

    public function testSendRefusedWhileAnotherSendIsInProgress()
    {
        $countBefore = CustomTable::getEloquent('safety_check_event')->getValueQuery()->count();
        $lockKey = SafetyCheckController::sendLockKey();
        \Cache::add($lockKey, 1, SafetyCheckController::SEND_LOCK_SECONDS);

        try {
            $response = $this->post('admin/safety_check/send', [
                'title' => 'Double click',
                'trigger_type' => 'manual',
            ]);
        } finally {
            \Cache::forget($lockKey);
        }

        $response->assertStatus(302);
        $countAfter = CustomTable::getEloquent('safety_check_event')->getValueQuery()->count();
        $this->assertEquals($countBefore, $countAfter, 'A send while the lock is held must not create an event.');
        Bus::assertNotDispatched(LineSendJob::class);
    }

    public function testSendLockOutlivesALongSyncSend()
    {
        $this->assertGreaterThanOrEqual(600, SafetyCheckController::SEND_LOCK_SECONDS);
    }

    public function testSendReleasesLockAndAllowsNextSend()
    {
        $countBefore = CustomTable::getEloquent('safety_check_event')->getValueQuery()->count();

        $this->post('admin/safety_check/send', ['title' => 'First', 'trigger_type' => 'manual'])->assertStatus(302);
        $this->assertFalse(\Cache::has(SafetyCheckController::sendLockKey()), 'lock must be released after send()');
        $this->post('admin/safety_check/send', ['title' => 'Second', 'trigger_type' => 'manual'])->assertStatus(302);

        $countAfter = CustomTable::getEloquent('safety_check_event')->getValueQuery()->count();
        $this->assertEquals($countBefore + 2, $countAfter);
    }

    public function testCloseSetsEventStatusClosed()
    {
        $event = $this->createEvent();

        $response = $this->post("admin/safety_check/{$event->id}/close");

        $response->assertStatus(302);

        $fresh = $this->freshEvent($event->id);
        $this->assertEquals('closed', $fresh->getValue('event_status'));
    }
}
