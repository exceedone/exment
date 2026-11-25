<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\UserSetting;
use Exceedone\Exment\Services\UserLocale;
use Exceedone\Exment\Services\UserTimezone;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * A notification to many users reads what each user needs once for all of them (ISS02906):
 * when the notification was sent to the user last (mail_send_log) and the settings of the user
 * (language, time zone). The decisions are the same as when each user is read alone.
 */
class NotifyManyUsersPreloadTest extends FeatureTestBase
{
    use DatabaseTransactions;

    public function testSentHistoriesGiveTheSameDecisions(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 26, 10, 0, 0));
        try {
            config(['exment.notify_saved_skip_minutes' => 5]);
            $loginUser = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
            $this->assertNotNull($loginUser);
            $this->be($loginUser, 'admin');

            $userTable = CustomTable::getEloquent(SystemTableName::USER);
            $users = $userTable->getValueModel()->query()->where('id', '<>', $loginUser->base_user_id)->orderBy('id')->take(4)->get();
            $this->assertGreaterThan(3, $users->count(), 'Fixture: four other users');
            $mailTemplate = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE)->getValueModel()->query()->first();
            $this->assertNotNull($mailTemplate);
            // the record notified about: a user record (any record of a table does)
            $record = $users[0];

            // sent 2 minutes ago to the first (skipped), 10 minutes ago to the second (not), none to the others
            $this->createMailSendLog($users[0], $record, $mailTemplate, Carbon::now()->subMinutes(2));
            $this->createMailSendLog($users[1], $record, $mailTemplate, Carbon::now()->subMinutes(10));

            $notify = new Notify();
            $notify->notify_trigger = NotifyTrigger::CREATE_UPDATE_DATA;
            $targets = $users->map(fn ($user) => NotifyTarget::getModelAsUser($user));

            $approval = new \ReflectionMethod(Notify::class, 'approvalSendUser');
            $approval->setAccessible(true);
            $preload = new \ReflectionMethod(Notify::class, 'preloadSentHistories');
            $preload->setAccessible(true);

            // one by one, as before
            $alone = [];
            foreach ($targets as $target) {
                $alone[] = $approval->invoke($notify, $mailTemplate, $userTable, $record, $target, true);
            }
            $this->assertSame([false, true, true, true], $alone);

            // read for all at once: the same, with one query
            $preload->invoke($notify, $mailTemplate, $userTable, $record, $targets);
            $queries = 0;
            \DB::listen(function ($query) use (&$queries) {
                if (str_contains($query->sql, getDBTableName(SystemTableName::MAIL_SEND_LOG))) {
                    $queries++;
                }
            });
            $together = [];
            foreach ($targets as $target) {
                $together[] = $approval->invoke($notify, $mailTemplate, $userTable, $record, $target, true);
            }
            $this->assertSame($alone, $together);
            $this->assertSame(0, $queries, 'no query a user after reading them at once');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testSettingsOfManyUsersAreTheSame(): void
    {
        $userTable = CustomTable::getEloquent(SystemTableName::USER);
        $ids = $userTable->getValueModel()->query()->orderBy('id')->take(5)->pluck('id')->all();
        $this->assertGreaterThan(2, count($ids));

        // a language and a time zone for one, nothing for the others
        $setting = UserSetting::where('base_user_id', $ids[1])->first() ?? new UserSetting();
        $setting->base_user_id = $ids[1];
        $setting->settings = ['user_setting' => ['locale' => 'en', 'timezone' => 'America/New_York']];
        $setting->save();
        UserSetting::where('base_user_id', $ids[2])->delete();

        $read = function () use ($ids) {
            $values = [];
            foreach ($ids as $id) {
                $values[$id] = [
                    UserLocale::userSetting($id, UserLocale::SETTING_KEY),
                    UserLocale::userSetting($id, UserTimezone::SETTING_KEY),
                ];
            }
            return $values;
        };

        UserLocale::clearCache();
        System::clearRequestSession();
        $alone = $read();
        $this->assertSame('en', $alone[$ids[1]][0]);

        UserLocale::clearCache();
        System::clearRequestSession();
        UserLocale::preloadSettings($ids);
        $queries = 0;
        \DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'user_settings')) {
                $queries++;
            }
        });
        $this->assertSame($alone, $read());
        $this->assertSame(0, $queries, 'no query a user after reading them at once');
    }

    private function createMailSendLog($target, $record, $mailTemplate, Carbon $sentAt): void
    {
        $log = CustomTable::getEloquent(SystemTableName::MAIL_SEND_LOG)->getValueModel();
        $log->setValue([
            'user' => $target->id,
            'mail_template' => $mailTemplate->id,
            'send_datetime' => $sentAt->format('Y-m-d H:i:s'),
        ]);
        $log->parent_id = $record->id;
        $log->parent_type = SystemTableName::USER;
        $log->save();
    }
}
