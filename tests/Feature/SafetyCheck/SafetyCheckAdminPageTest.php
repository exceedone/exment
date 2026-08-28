<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

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

/**
 * Task 8 - admin page (admin/safety_check): send a new safety-check event
 * (manual/drill), re-send to still-unanswered users (throttled to once per 5
 * minutes), and close an event. Every action requires the system permission,
 * exercised here as the fixture admin user.
 */
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

    // -------------------------------------------------- helpers

    /** Re-fetch an event via a fresh query (bypasses the request-session cache). */
    protected function freshEvent($id)
    {
        return CustomTable::getEloquent('safety_check_event')->getValueQuery()->find($id);
    }

    protected function latestEvent()
    {
        return CustomTable::getEloquent('safety_check_event')->getValueQuery()
            ->orderBy('id', 'desc')->first();
    }

    // -------------------------------------------------- tests

    /** 1. GET the page: 200, and the page shows the menu_title text. */
    public function testIndexPageShowsMenuTitle()
    {
        $response = $this->get('admin/safety_check');

        $response->assertStatus(200);
        $response->assertSee(exmtrans('safety.menu_title'));
    }

    /** 2. POST send: creates a manual event + answer rows for every user + dispatches jobs for linked users. */
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

    /** 3. POST send with trigger_type=drill: the created event's trigger_type is 'drill'. */
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

    /**
     * 4. POST resend within 5 minutes of a previous resend (seeded resent_at = 2 minutes ago): throttled,
     * no extra job. The event has a real `not_answered` answer row for a LINE-linked user, so the throttle
     * check is what prevents the dispatch -- without it, SafetyCheckSender::send($event, true) WOULD
     * dispatch a job for this user, making this assertion meaningful (not vacuously true from zero rows).
     */
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

    /**
     * The resend throttle is a system setting, not a hardcoded 5 minutes: with
     * safety_check_resend_throttle_minutes lowered to 1, a resend 2 minutes after the
     * previous one (throttled under the default) MUST go through and dispatch a job.
     */
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

    /**
     * The list size is the grid's own per-page selector, NOT a system setting:
     * the safety_check_index_limit setting was removed because this already
     * covers it. Regression guard for that removal — with per_page=1 only the
     * newest event shows.
     */
    public function testPerPageSelectorControlsListSize()
    {
        $this->createEvent(['title' => 'zz_older_event_hidden']);
        $this->createEvent(['title' => 'zz_newer_event_visible']);

        $response = $this->get('admin/safety_check?per_page=1');

        $response->assertStatus(200);
        $response->assertSee('zz_newer_event_visible');
        $response->assertDontSee('zz_older_event_hidden');
    }

    /**
     * A successful (non-throttled) resend: only the still-`not_answered` linked user gets a job, no new
     * answer rows are created, and `resent_at` is updated. This proves the controller calls
     * SafetyCheckSender::send($event, true) -- not send($event) -- on the resend route.
     */
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

        // Resend backfills a row for every user missing one (recovery path for rows
        // whose creation failed at the first send) — but still only PUSHES to linked
        // users whose status is not_answered (asserted above: exactly 1 job).
        $rowsAfter = $this->answerRows($event->id);
        $this->assertEquals($this->totalUserCount(), $rowsAfter->count(), 'A resend must backfill missing answer rows for all users.');
        $this->assertGreaterThan($rowCountBefore, $rowsAfter->count());

        $fresh = $this->freshEvent($event->id);
        $this->assertNotNull($fresh->getValue('resent_at'), 'A successful resend must set resent_at.');
    }

    /**
     * Resending a closed event must be blocked entirely: closed events' Flex buttons can
     * only reply "closed" (see SafetyCheckAction::handle), so resending would just send
     * paid LINE messages nobody can meaningfully answer. No job dispatched, event unchanged.
     */
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

    /** POST send with an empty title: no event created, no jobs dispatched. */
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

    /** 5. POST close: event_status becomes 'closed'. */
    public function testCloseSetsEventStatusClosed()
    {
        $event = $this->createEvent();

        $response = $this->post("admin/safety_check/{$event->id}/close");

        $response->assertStatus(302);

        $fresh = $this->freshEvent($event->id);
        $this->assertEquals('closed', $fresh->getValue('event_status'));
    }
}
