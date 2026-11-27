<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Notifications\MailSender;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * A notify on saving data is not sent again to the same user for the same record within
 * exment.notify_saved_skip_minutes (5): Notify::approvalSendUser() looks for the mail sent
 * log of the record (parent_id, parent_type). MailChannel::saveHistory() gave them to
 * setValue(), which keeps only custom columns, so the log had no record and every save
 * sent the notify again.
 */
class NotifyMailDuplicateTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /** @var CustomValue */
    private $user1;

    /** @var Notify */
    private $notify;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Carbon::setTestNow(Carbon::create(2026, 11, 27, 10, 0, 0));

        $admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertInstanceOf(LoginUser::class, $admin);
        $this->be($admin);

        $user1 = getModelName(SystemTableName::USER)::where('value->user_code', 'user1')->first();
        $this->assertNotNull($user1);
        $this->user1 = $user1;

        $target = function ($action) {
            return [
                'notify_action' => $action,
                'notify_action_target' => [NotifyActionTarget::FIXED_USER],
                'target_users' => [$this->user1->id],
            ];
        };
        $notify = new Notify();
        $notify->notify_view_name = 'notify_mail_duplicate_test';
        $notify->target_id = $this->table()->id;
        $notify->notify_trigger = NotifyTrigger::CREATE_UPDATE_DATA;
        $notify->mail_template_id = 6;
        $notify->active_flg = 1;
        $notify->trigger_settings = ['notify_saved_trigger' => ['updated']];
        $notify->action_settings = [$target(NotifyAction::EMAIL), $target(NotifyAction::SHOW_PAGE)];
        $notify->save();
        $this->notify = $notify;
        System::clearCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        System::clearCache();
        parent::tearDown();
    }

    private function table(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
    }

    private function createRecord(string $text): CustomValue
    {
        $value = $this->table()->getValueModel();
        $value->setValue('text', $text)->save();
        return $value;
    }

    private function update(CustomValue $value, string $text): void
    {
        $value->setValue('text', $text)->save();
    }

    private function at(int $minutes, int $seconds = 0): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 27, 10, $minutes, $seconds));
    }

    /**
     * @return \Illuminate\Support\Collection<int, mixed> the mail sent log of the record for user1
     */
    private function mailLogs(CustomValue $value)
    {
        // as Notify::approvalSendUser() looks for it
        $index_user = CustomColumn::getEloquent('user', SystemTableName::MAIL_SEND_LOG)->getIndexColumnName();
        return getModelName(SystemTableName::MAIL_SEND_LOG)::query()
            ->where($index_user, $this->user1->id)
            ->where('parent_id', $value->id)
            ->where('parent_type', $this->table()->table_name)
            ->get();
    }

    private function alertCount(CustomValue $value): int
    {
        return NotifyNavbar::withoutGlobalScopes()
            ->where('notify_id', $this->notify->id)
            ->where('parent_id', $value->id)
            ->where('target_user_id', $this->user1->id)
            ->count();
    }

    public function testTheMailLogKeepsTheRecord(): void
    {
        $value = $this->createRecord('first');
        $this->update($value, 'updated');

        $logs = $this->mailLogs($value);
        $this->assertCount(1, $logs, 'the log has the record');
        // the mail just sent by this notify does not stop its alert to the same user
        $this->assertSame(1, $this->alertCount($value), 'the alert is sent with the mail');
        $log = $logs->first();
        $this->assertSame((string)$value->id, (string)$log->parent_id);
        $this->assertSame(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL, $log->parent_type, 'parent_type is the table name');
        // the other values are saved as before
        $this->assertSame('2026-11-27 10:00:00', $log->getValue('send_datetime'));
        $this->assertNotEmpty($log->getValue('mail_subject'));
        $this->assertSame((string)$this->notify->mail_template_id, (string)array_get($log->value, 'mail_template'));
        // the record of the log is the record
        $parent = $log->getParentValue();
        $this->assertNotNull($parent);
        $this->assertSame($value->id, $parent->id);
    }

    public function testASecondSaveWithinTheMinutesIsNotSentAgain(): void
    {
        $value = $this->createRecord('first');
        $this->update($value, 'update 1');
        $this->assertCount(1, $this->mailLogs($value));
        $this->assertSame(1, $this->alertCount($value));

        // 1 minute later, and just before the 5 minutes
        $this->at(1);
        $this->update($value, 'update 2');
        $this->at(4, 59);
        $this->update($value, 'update 3');

        $this->assertCount(1, $this->mailLogs($value), 'one mail');
        $this->assertSame(1, $this->alertCount($value), 'one alert');
    }

    public function testASaveAfterTheMinutesIsSentAgain(): void
    {
        $value = $this->createRecord('first');
        $this->update($value, 'update 1');

        $this->at(5, 1);
        $this->update($value, 'update 2');

        $this->assertCount(2, $this->mailLogs($value));
        $this->assertSame(2, $this->alertCount($value));
    }

    public function testTheMinutesOfTheSettingAreUsed(): void
    {
        config(['exment.notify_saved_skip_minutes' => 1]);
        $value = $this->createRecord('first');
        $this->update($value, 'update 1');

        $this->at(0, 59);
        $this->update($value, 'update 2');
        $this->assertCount(1, $this->mailLogs($value));

        $this->at(1, 1);
        $this->update($value, 'update 3');
        $this->assertCount(2, $this->mailLogs($value));
    }

    public function testAnotherRecordIsSentOnItsOwn(): void
    {
        $value1 = $this->createRecord('record 1');
        $value2 = $this->createRecord('record 2');

        $this->update($value1, 'record 1 updated');
        $this->at(1);
        $this->update($value2, 'record 2 updated');

        $this->assertCount(1, $this->mailLogs($value1));
        $this->assertCount(1, $this->mailLogs($value2));
        $this->assertSame(1, $this->alertCount($value2));
    }

    /**
     * A mail that is not about a record (the test mail, a system mail) is logged as before,
     * with no record.
     */
    public function testAMailWithoutARecordIsLoggedAsBefore(): void
    {
        $modelname = getModelName(SystemTableName::MAIL_SEND_LOG);
        $before = $modelname::query()->count();

        MailSender::make(null, 'notify-mail-duplicate@example.com')
            ->subject('mail without record')
            ->body('body')
            ->send();

        $this->assertSame($before + 1, $modelname::query()->count());
        $log = $modelname::query()->orderByDesc('id')->first();
        $this->assertSame('notify-mail-duplicate@example.com', $log->getValue('mail_to'));
        $this->assertSame('mail without record', $log->getValue('mail_subject'));
        $this->assertNull($log->parent_id);
        $this->assertNull($log->parent_type);
        $this->assertNull($log->getParentValue());
    }
}
