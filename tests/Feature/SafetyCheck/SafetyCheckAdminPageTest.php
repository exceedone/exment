<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Exceedone\Exment\Controllers\SafetyCheckController;
use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Jobs\SafetyCheckSendJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckDefine;
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

    /**
     * @param int|string $id
     * @return \Exceedone\Exment\Model\CustomValue|null
     */
    protected function freshEvent($id)
    {
        /** @var \Exceedone\Exment\Model\CustomValue|null $event */
        $event = CustomTable::getEloquent('safety_check_event')->getValueQuery()->find($id);
        return $event;
    }

    /**
     * @return \Exceedone\Exment\Model\CustomValue|null
     */
    protected function latestEvent()
    {
        /** @var \Exceedone\Exment\Model\CustomValue|null $event */
        $event = CustomTable::getEloquent('safety_check_event')->getValueQuery()
            ->orderBy('id', 'desc')->first();
        return $event;
    }

    public function testIndexPageShowsMenuTitle(): void
    {
        $response = $this->get('admin/safety_check');

        $response->assertStatus(200);
        $response->assertSee(exmtrans('safety.menu_title'));
    }

    public function testIndexRendersEventStatusAsBadgeAndBootstrap5Form(): void
    {
        $this->createEvent(['title' => 'Badge event open']);
        $this->createEvent(['title' => 'Badge event closed', 'event_status' => SafetyCheckDefine::EVENT_CLOSED]);

        $response = $this->get('admin/safety_check');

        $response->assertStatus(200);
        $response->assertSee('badge bg-success', false);
        $response->assertSee('badge bg-secondary', false);
        $response->assertSee('class="form-select"', false);
        $response->assertSee('swal({', false);
        $response->assertDontSee('label label-', false);
        $response->assertDontSee('form-inline', false);
    }

    public function testIndexEscapesTriggerTypeCell(): void
    {
        // trigger_type is normally manual/drill/jma_auto, but the column is stored as-is
        // and the admin-core grid outputs display() results raw.
        $this->createEvent(['title' => 'Escape event', 'trigger_type' => '<b>tt-raw</b>']);

        $response = $this->get('admin/safety_check');

        $response->assertStatus(200);
        $response->assertDontSee('<b>tt-raw</b>', false);
        $response->assertSee('&lt;b&gt;tt-raw&lt;/b&gt;', false);
    }

    public function testIndexPageLoadsSweetalertBuildWithLegacySwalAlias(): void
    {
        // rowActionScript() gọi swal({type:'warning'}) — API của sweetalert2 7.x.
        // admin-core có cả bản 7.x (dist/, có alias window.swal) lẫn 10.x (không có alias).
        // Nếu baseJs đổi sang bản không có alias, test này đỏ trước khi user thấy nút 再送/締切 chết.
        $html = (string) $this->get('admin/safety_check')->getContent();

        preg_match_all('#<script[^>]*\ssrc="[^"]*/vendor/open-admin/([^"?]*sweetalert2[^"?]*)#', $html, $m);
        $this->assertNotEmpty($m[1], 'admin/safety_check must load a sweetalert2 script');

        // public/vendor/open-admin is published from exment-admin-core resources/assets (tag open-admin-assets).
        $withAlias = array_filter($m[1], function ($path) {
            $file = base_path('vendor/exceedone/exment-admin-core/resources/assets/' . $path);
            $js = is_file($file) ? (string) file_get_contents($file) : '';
            return str_contains($js, 'window.swal=') && str_contains($js, 'version="7.');
        });
        $this->assertNotEmpty($withAlias, 'no loaded sweetalert2 build defines window.swal (7.x): ' . implode(', ', $m[1]));
    }

    /**
     * The system-admin gate of every action (index/send/resend/close).
     *
     * Tested on the method, not through a request: a non-system user is already
     * stopped by the admin.permission middleware of exment-admin-core, which renders
     * 権限がありません and sends it through Pjax::respond() — that calls exit and would
     * kill the PHPUnit process. The controller gate is the defense in depth behind it,
     * and it must not be constructor middleware (see ControllerInstanceMiddlewareGuardTest).
     */
    public function testAuthorizeSystemAdminAllowsAdminAndRejectsNonSystemUser(): void
    {
        $controller = new SafetyCheckController();
        $gate = new \ReflectionMethod($controller, 'authorizeSystemAdmin');
        $gate->setAccessible(true);

        // setUp() logged the system admin in: the gate must stay silent.
        $gate->invoke($controller);

        $loginUser = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertNotNull($loginUser, 'Fixture: a non-system login user is required.');
        $this->be($loginUser, 'admin');
        System::clearRequestSession();

        try {
            $gate->invoke($controller);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            return;
        }
        $this->fail('a non-system user must be refused with 403');
    }

    public function testSendCreatesManualEventAndDispatchesJobs(): void
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

    public function testSendDrillSetsTriggerType(): void
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

    public function testResendThrottledWithinFiveMinutesDispatchesNoJob(): void
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

    public function testResendThrottleSettingRespected(): void
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

    public function testPerPageSelectorControlsListSize(): void
    {
        $this->createEvent(['title' => 'zz_older_event_hidden']);
        $this->createEvent(['title' => 'zz_newer_event_visible']);

        $response = $this->get('admin/safety_check?per_page=1');

        $response->assertStatus(200);
        $response->assertSee('zz_newer_event_visible');
        $response->assertDontSee('zz_older_event_hidden');
    }

    public function testResendSendsOnlyToUnansweredUsersAndUpdatesResentAt(): void
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

    public function testResendOnClosedEventIsBlocked(): void
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

    public function testSendWithEmptyTitleCreatesNoEventAndDispatchesNoJob(): void
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

    public function testSendRefusedWhileAnotherSendIsInProgress(): void
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

    public function testSendLockOutlivesALongSend(): void
    {
        $this->assertGreaterThanOrEqual(600, SafetyCheckController::SEND_LOCK_SECONDS);
    }

    public function testSendReleasesLockAndAllowsNextSend(): void
    {
        $countBefore = CustomTable::getEloquent('safety_check_event')->getValueQuery()->count();

        $this->post('admin/safety_check/send', ['title' => 'First', 'trigger_type' => 'manual'])->assertStatus(302);
        $this->assertFalse(\Cache::has(SafetyCheckController::sendLockKey()), 'lock must be released after send()');
        $this->post('admin/safety_check/send', ['title' => 'Second', 'trigger_type' => 'manual'])->assertStatus(302);

        $countAfter = CustomTable::getEloquent('safety_check_event')->getValueQuery()->count();
        $this->assertEquals($countBefore + 2, $countAfter);
    }

    public function testSendRunsAfterTheResponseAndKeepsTheLockUntilThen(): void
    {
        Bus::fake([LineSendJob::class, SafetyCheckSendJob::class]);

        $this->post('admin/safety_check/send', ['title' => 'After response', 'trigger_type' => 'manual'])->assertStatus(302);

        $event = $this->latestEvent();
        $this->assertNotNull($event, 'The event itself is created before the response.');
        Bus::assertDispatchedAfterResponse(SafetyCheckSendJob::class);
        $this->assertCount(0, $this->answerRows($event->id), 'The admin must not wait for the rows, mails and LINE messages of every user.');
        $this->assertTrue(\Cache::has(SafetyCheckController::sendLockKey()), 'A second click must stay refused until the send has run.');
    }

    public function testResendRunsAfterTheResponse(): void
    {
        Bus::fake([LineSendJob::class, SafetyCheckSendJob::class]);
        $event = $this->createEvent();

        $this->post("admin/safety_check/{$event->id}/resend")->assertStatus(302);

        Bus::assertDispatchedAfterResponse(SafetyCheckSendJob::class);
        $this->assertCount(0, $this->answerRows($event->id));
    }

    public function testSendJobSendsAndReleasesTheLock(): void
    {
        $event = $this->createEvent();
        $lockKey = SafetyCheckController::sendLockKey();
        \Cache::add($lockKey, 1, SafetyCheckController::SEND_LOCK_SECONDS);

        (new SafetyCheckSendJob($event, false, $lockKey))->handle();

        $this->assertEquals($this->totalUserCount(), $this->answerRows($event->id)->count());
        $this->assertFalse(\Cache::has($lockKey), 'The lock must go once the send is done.');
    }

    public function testCloseSetsEventStatusClosed(): void
    {
        $event = $this->createEvent();

        $response = $this->post("admin/safety_check/{$event->id}/close");

        $response->assertStatus(302);

        $fresh = $this->freshEvent($event->id);
        $this->assertEquals('closed', $fresh->getValue('event_status'));
    }
}
