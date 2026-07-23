<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Carbon\Carbon;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Services\Line\LineSendLogger;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/**
 * Phase 4 - Reminder TIME -> LINE. exment:notifyschedule / Notify::notifySchedule()
 * sends reminders via LINE to linked users, and prevents duplicate sends when the batch runs repeatedly.
 *
 * Does not call the real LINE API (Http::fake) and does not dispatch real pushes (Bus::fake).
 */
class NotifyScheduleLineTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        config(['exment.line.dedupe_minutes' => 0]); // reset so state does not leak between tests
        Http::fake(['api.line.me/*' => Http::response('{}', 200)]);
    }

    /** Read a protected property of LineSendJob. */
    protected function jobProperty(LineSendJob $job, string $name)
    {
        $prop = (new \ReflectionClass($job))->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($job);
    }

    /**
     * Build a TIME notify + LINE action for a due record, and link LINE for the recipient.
     * @return array [$notify, $table, int $recordId, int $recipientUserId, string $lineUserId]
     */
    protected function setupTimeReminder($flexTemplateId = null): array
    {
        $hh = Carbon::now()->format('G');
        $targetDate = Carbon::today()->addDays(100)->format('Y-m-d');
        $loginUserId = \Exment::user()->base_user_id;

        /** @var Notify $notify */
        $notify = Notify::where('notify_trigger', NotifyTrigger::TIME)->first();
        $notify->setTriggerSetting('notify_hour', $hh);
        $notify->setTriggerSetting('notify_day', 100);
        $notify->setTriggerSetting('notify_beforeafter', -1);

        $action = [
            'notify_action' => NotifyAction::LINE,
            'notify_action_target' => [NotifyActionTarget::CREATED_USER],
        ];
        if ($flexTemplateId !== null) {
            $action['flex_template_id'] = $flexTemplateId;
        }
        $notify->action_settings = [$action];
        $notify->save();

        /** @var CustomTable $table */
        $table = CustomTable::find($notify->target_id);
        $record = $table->getValueModel()
            ->where('created_user_id', '<>', $loginUserId)->first();
        $this->assertNotNull($record, 'Fixture: a record created by another user is required.');
        $record->update(['value->date' => $targetDate]);

        $recipientUserId = (int) $record->created_user_id;
        $lineUserId = 'Ureminder' . $recipientUserId;
        LineAccountLink::forUser($recipientUserId)->markLinked($lineUserId);

        return [$notify, $table, (int) $record->id, $recipientUserId, $lineUserId];
    }

    /** Seed one line_send_log row for (user, record) — used by dedupe tests. */
    protected function seedLog(int $userId, int $parentId, string $parentType): void
    {
        LineSendLogger::record(
            [
                'line_user_id' => 'Useed' . $userId,
                'user_id' => $userId,
                'parent_id' => $parentId,
                'parent_type' => $parentType,
                'message_type' => LineSendLogger::TYPE_TEXT,
                'subject' => 'seed',
                'save_body' => true,
            ],
            [['type' => 'text', 'text' => 'seed']],
            ['ok' => true, 'status' => 200]
        );
    }

    // -------------------------------------------------- pipeline

    public function testReminderTextDispatchesLineJobForLinkedUser()
    {
        Bus::fake();
        [$notify, $table, $recordId, , $lineUserId] = $this->setupTimeReminder();

        $notify->notifySchedule();

        Bus::assertDispatchedAfterResponse(LineSendJob::class, function ($job) use ($lineUserId, $recordId, $table) {
            if ($this->jobProperty($job, 'to') !== $lineUserId) {
                return false;
            }
            $context = $this->jobProperty($job, 'context');
            $this->assertEquals($recordId, $context['parent_id']);
            $this->assertEquals($table->table_name, $context['parent_type']);
            $this->assertEquals('text', $context['message_type']);
            return true;
        });
    }

    public function testReminderSkipsUnlinkedUser()
    {
        Bus::fake();
        [$notify, , , $recipientUserId] = $this->setupTimeReminder();
        // remove the link created above -> user has no line_user_id
        LineAccountLink::where('user_id', $recipientUserId)->delete();

        $notify->notifySchedule();

        Bus::assertNotDispatchedAfterResponse(LineSendJob::class);
    }

    public function testReminderWithFlexTemplateDispatchesFlexJob()
    {
        Bus::fake();

        // create a minimal flex template
        $tmpl = CustomTable::getEloquent('line_flex_template')->getValueModel();
        $tmpl->setValue([
            'template_name' => 'reminder-test',
            'title' => '${value}',
            'body_items' => '',
        ]);
        $tmpl->save();

        [$notify] = $this->setupTimeReminder((int) $tmpl->id);

        $notify->notifySchedule();

        Bus::assertDispatchedAfterResponse(LineSendJob::class, function ($job) use ($tmpl) {
            $messages = $this->jobProperty($job, 'messages');
            $this->assertEquals('flex', $messages[0]['type']);
            $context = $this->jobProperty($job, 'context');
            $this->assertEquals('flex', $context['message_type']);
            $this->assertEquals((int) $tmpl->id, (int) $context['flex_template_id']);
            return true;
        });
    }

    public function testDedupeOffSendsOnEveryRun()
    {
        Bus::fake();
        config(['exment.line.dedupe_minutes' => 0]);
        [$notify] = $this->setupTimeReminder();

        $notify->notifySchedule();
        $notify->notifySchedule();

        // dedupe off -> one job per run (2 runs = 2 jobs)
        Bus::assertDispatchedAfterResponseTimes(LineSendJob::class, 2);
    }

    public function testScheduleCommandDispatchesReminder()
    {
        Bus::fake();
        [, , , , $lineUserId] = $this->setupTimeReminder();

        \Artisan::call('exment:notifyschedule');

        Bus::assertDispatchedAfterResponse(LineSendJob::class, function ($job) use ($lineUserId) {
            return $this->jobProperty($job, 'to') === $lineUserId;
        });
    }

    // -------------------------------------------------- dedupe

    public function testDedupeBlocksSecondSendWithinWindow()
    {
        Bus::fake();
        config(['exment.line.dedupe_minutes' => 60]);
        [$notify, $table, $recordId, $recipientUserId] = $this->setupTimeReminder();

        // a log already exists within the 60-minute window for this exact (user, record) -> this run must be blocked
        $this->seedLog($recipientUserId, $recordId, $table->table_name);

        $notify->notifySchedule();

        Bus::assertNotDispatchedAfterResponse(LineSendJob::class);
    }

    public function testDedupeAllowsSendWhenLogOlderThanWindow()
    {
        Bus::fake();
        config(['exment.line.dedupe_minutes' => 60]);
        [$notify, $table, $recordId, $recipientUserId] = $this->setupTimeReminder();

        // log is 2 hours old -> outside the 60-minute window -> should still send
        Carbon::setTestNow(Carbon::now()->subHours(2));
        $this->seedLog($recipientUserId, $recordId, $table->table_name);
        Carbon::setTestNow();

        $notify->notifySchedule();

        Bus::assertDispatchedAfterResponse(LineSendJob::class);
    }

    public function testDedupeDoesNotApplyToNonTimeTrigger()
    {
        Bus::fake();
        config(['exment.line.dedupe_minutes' => 60]);
        [, $table, $recordId, $recipientUserId, $lineUserId] = $this->setupTimeReminder();

        // a log exists within the window, BUT the notify is WORKFLOW -> the guard does not apply -> still sends
        $this->seedLog($recipientUserId, $recordId, $table->table_name);

        $record = $table->getValueModel()->find($recordId);
        $wfNotify = Notify::where('notify_trigger', NotifyTrigger::WORKFLOW)->first();
        $this->assertNotNull($wfNotify, 'Fixture: a WORKFLOW notify is required.');
        $user = \Exceedone\Exment\Model\NotifyTarget::getModelAsUser(LoginUser::find($recipientUserId)->base_user);

        \Exceedone\Exment\Services\NotifyService::notifyLine([
            'notify' => $wfNotify,
            'user' => $user,
            'custom_value' => $record,
            'subject' => 'wf subject',
            'body' => 'wf body',
        ]);

        Bus::assertDispatchedAfterResponse(LineSendJob::class, function ($job) use ($lineUserId) {
            return $this->jobProperty($job, 'to') === $lineUserId;
        });
    }
}
