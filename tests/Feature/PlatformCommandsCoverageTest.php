<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\MailSendJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Notifications\MailSender;
use Exceedone\Exment\Services\Line\LineAccountLinker;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\PendingCommand;

/**
 * Artisan commands whose effect stays in this test's database transaction (or is faked):
 * exment:notify, exment:notifytest, exment:line-linkcode, exment:resetpassword and
 * exment:schedule, plus the refusals of exment:refreshdata / exment:refreshtable.
 */
class PlatformCommandsCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        System::clearCache();
        parent::tearDown();
    }

    public function testNotifyRunsTheGivenScheduledNotify(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $userId = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1)->base_user_id;
        Carbon::setTestNow(Carbon::now());

        // as NotifyTest::testNotifySchedule(): 365 days ahead is outside the seeded dates
        // (today + 0..100), so only the record changed below is due. The command runs the
        // notify it is given whatever its hour.
        $notifyDay = 365;
        $notify = Notify::where('notify_trigger', NotifyTrigger::TIME)->first();
        $this->assertNotNull($notify);
        $notify->setTriggerSetting('notify_day', $notifyDay);
        $notify->setTriggerSetting('notify_beforeafter', -1);
        $notify->save();

        $customTable = CustomTable::getEloquent($notify->target_id);
        $this->assertNotNull($customTable);
        $model = $customTable->getValueModel()->where('created_user_id', '<>', $userId)->first();
        $this->assertNotNull($model);
        $model->update(['value->date' => Carbon::today()->addDays($notifyDay)->format('Y-m-d')]);

        $this->runArtisan('exment:notify', ['id' => $notify->id])
            ->assertExitCode(0)
            ->run();

        // the seeded notify shows a notice to the creator of the record
        $navbar = NotifyNavbar::withoutGlobalScopes()
            ->where('target_user_id', $model->created_user_id)
            ->where('parent_type', $customTable->table_name)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        $this->assertNotNull($navbar);
        $this->assertEquals($model->id, $navbar->parent_id);
        $this->assertEquals($userId, $navbar->trigger_user_id);
    }

    public function testNotifytestSendsATestMailToTheAddress(): void
    {
        $fake = Notification::fake();

        $this->runArtisan('exment:notifytest', ['--to' => 'claude-notifytest@example.com'])
            ->expectsOutput('Send mail Success.')
            ->assertExitCode(0)
            ->run();

        // the MailSender is the notifiable, keyed by its address (MailSender::getKey())
        $fake->assertSentTimes(MailSendJob::class, 1);
        $sent = $fake->sentNotifications()[MailSender::class]['claude-notifytest@example.com'][MailSendJob::class] ?? [];
        $this->assertCount(1, $sent);
        $sender = $sent[0]['notifiable'];
        $this->assertInstanceOf(MailSender::class, $sender);
        // NotifyService::executeTestNotify() fills in the subject and the body
        $this->assertEquals('Exment TestMail', $sender->getSubject());
        $this->assertEquals('Exment TestMail', $sender->getBody());
    }

    public function testLineLinkcodeIssuesALinkCodeForTheUser(): void
    {
        $userId = (int) LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1)->base_user_id;

        // the code is random: run without the console mock and read the output afterwards
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('exment:line-linkcode', ['userId' => $userId]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $link = LineAccountLink::where('user_id', $userId)->first();
        $this->assertNotNull($link);
        $this->assertTrue($link->hasActiveCode(), 'the code is stored with an expiry in the future');
        $this->assertNull($link->line_user_id);
        $code = (string) $link->line_link_code;
        $this->assertMatchesRegularExpression('/^[0-9A-F]{12}$/', $code);
        $this->assertStringContainsString(exmtrans('line.linkcode_code', $code), $output);
        $this->assertStringContainsString('Deep link: ' . (new LineAccountLinker())->deepLink($code), $output);
    }

    public function testResetpasswordSetsTheGivenPassword(): void
    {
        $password = 'Claude-Reset-2468';

        $this->runArtisan('exment:resetpassword', ['--user_code' => 'user2', '--password' => $password])
            ->expectsOutput(exmtrans('command.resetpassword.success'))
            ->assertExitCode(0)
            ->run();

        $loginUser = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertTrue(Hash::check($password, (string) $loginUser->password));
    }

    public function testScheduleRunsTheOperationLogPurgeThatIsDue(): void
    {
        // at 10:30 no batch plugin is due (the test batch plugin runs at 3, an unset hour only
        // at 0), nor the seeded time notify (hour 2); the automatic backup is turned off below
        Carbon::setTestNow(Carbon::create(2026, 10, 19, 10, 30, 0));
        System::backup_enable_automatic(false);
        // a purge every run, keeping 100 years: nothing is deleted, the run is recorded
        System::operation_log_enable_automatic(true);
        System::operation_log_keep_days(36500);
        System::operation_log_automatic_week(null);
        System::operation_log_automatic_month(null);
        System::operation_log_automatic_day(null);
        System::operation_log_automatic_hour(null);
        System::operation_log_automatic_minute(null);
        System::operation_log_automatic_executed(null);
        System::clearCache();

        $this->runArtisan('exment:schedule')
            ->assertExitCode(0)
            ->run();

        // ScheduleCommand::clearOperationLog() records the run once exment:log-clear succeeded
        System::clearCache();
        $executed = System::operation_log_automatic_executed();
        $this->assertInstanceOf(Carbon::class, $executed);
        $this->assertSame('2026-10-19 10:30:00', $executed->toDateTimeString());
    }

    // A confirmed refresh TRUNCATEs the tables (RefreshDataService): that commits this test's
    // transaction and empties the shared test data, so only the refusals are run.

    public function testRefreshdataChangesNothingWhenNotConfirmed(): void
    {
        $count = $this->recordCount(TestDefine::TESTDATA_TABLE_NAME_EDIT);

        // handle() returns without a value when the question is declined: exit code 0
        $this->runArtisan('exment:refreshdata')
            ->expectsConfirmation('Really refresh data? All refresh custom data.', 'no')
            ->assertExitCode(0)
            ->run();

        $this->assertSame($count, $this->recordCount(TestDefine::TESTDATA_TABLE_NAME_EDIT));
    }

    public function testRefreshtableRefusesWhatItMustNotRefresh(): void
    {
        $this->runArtisan('exment:refreshtable', ['table_name' => 'claude_no_such_table'])
            ->expectsOutput('Table claude_no_such_table are not found.')
            ->assertExitCode(1)
            ->run();

        // the user, organization and mail template tables are never refreshed
        $this->runArtisan('exment:refreshtable', ['table_name' => SystemTableName::USER])
            ->expectsOutput('Table ' . SystemTableName::USER . ' cannot refresh.')
            ->assertExitCode(1)
            ->run();

        $count = $this->recordCount(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $this->runArtisan('exment:refreshtable', ['table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT])
            ->expectsConfirmation('Really refresh data? All refresh custom data.', 'no')
            ->assertExitCode(1)
            ->run();
        $this->assertSame($count, $this->recordCount(TestDefine::TESTDATA_TABLE_NAME_EDIT));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        // artisan() only answers an int when console output mocking is off
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    private function recordCount(string $tableName): int
    {
        $table = CustomTable::getEloquent($tableName);
        $this->assertNotNull($table);

        return getModelName($table)::query()->withoutGlobalScope(CustomValueModelScope::class)->count();
    }
}
