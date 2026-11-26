<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\NotifyUserJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Notify\NotifyRunner;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;

/**
 * The notifications of a saved record are made after the response or by the queue, not while
 * the save waits (ISS02906). The same users get the same bells as when the save made them.
 */
class NotifyLaterTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        config(['exment.notify_mode' => 'auto']);
        parent::tearDown();
    }

    public function testMode(): void
    {
        config(['exment.notify_mode' => 'auto', 'queue.default' => 'database']);
        $this->assertSame(NotifyRunner::QUEUE, NotifyRunner::mode());

        // a command (the tests too): at once, as before
        config(['queue.default' => 'sync']);
        $this->assertSame(NotifyRunner::SYNC, NotifyRunner::mode());

        foreach ([NotifyRunner::QUEUE, NotifyRunner::AFTER_RESPONSE, NotifyRunner::SYNC] as $mode) {
            config(['exment.notify_mode' => $mode]);
            $this->assertSame($mode, NotifyRunner::mode());
        }
    }

    public function testTheSameBellsAfterTheResponse(): void
    {
        $sync = $this->bells(NotifyRunner::SYNC, 'later_sync');
        $this->assertSame(['user1', 'user2'], $sync['got']);

        $later = $this->bells(NotifyRunner::AFTER_RESPONSE, 'later_after');
        $this->assertSame([], $later['before'], 'no bell before the response is sent');
        $this->assertSame($sync['got'], $later['got']);
        $this->assertSame($sync['subjects'], $later['subjects']);
    }

    public function testTheSameBellsByTheQueue(): void
    {
        $sync = $this->bells(NotifyRunner::SYNC, 'later_sync_q');

        // only this job is held (the bells it makes go through the sync queue as always)
        $fake = Queue::fake([NotifyUserJob::class]);
        $queued = $this->bells(NotifyRunner::QUEUE, 'later_queue');
        $this->assertSame([], $queued['got'], 'nothing while the save waits');
        // one job for this notification (the other notifications of the table have their own)
        $notifyId = intval($queued['notify']->id);
        $jobs = $fake->pushed(NotifyUserJob::class, fn (NotifyUserJob $job) => $job->notifyId === $notifyId);
        $this->assertCount(1, $jobs);

        // the worker runs the job: the same bells
        foreach ($jobs as $job) {
            \Auth::guard('admin')->logout();
            $job->handle();
        }
        $got = $this->namesOf($queued['notify']);
        $this->assertSame($sync['got'], $got);
    }

    /**
     * The bells written at once have the same columns as the bells saved one by one.
     */
    public function testTheBellsWrittenAtOnceAreTheSame(): void
    {
        $sync = $this->bells(NotifyRunner::SYNC, 'bulk_sync');
        $later = $this->bells(NotifyRunner::AFTER_RESPONSE, 'bulk_later');

        $columns = function (Notify $notify): array {
            return NotifyNavbar::withoutGlobalScopes()->where('notify_id', $notify->id)->orderBy('target_user_id')->get()
                ->map(function ($bell) {
                    $row = $bell->getAttributes();
                    // the record and the notification differ (two saves), the rest is the same
                    unset($row['id'], $row['notify_id'], $row['parent_id'], $row['notify_subject'], $row['notify_body'], $row['created_at'], $row['updated_at']);
                    ksort($row);
                    return $row;
                })->all();
        };
        $this->assertCount(2, $columns($later['notify']));
        $this->assertEquals($columns($sync['notify']), $columns($later['notify']));
    }

    public function testTheShareAndTheDeleteStayAtOnce(): void
    {
        config(['exment.notify_mode' => NotifyRunner::AFTER_RESPONSE]);
        $this->assertFalse(NotifyRunner::defers('shared', ['targetUserOrgs' => []]));
        $this->assertFalse(NotifyRunner::defers('deleted', []));
        $this->assertTrue(NotifyRunner::defers('updated', []));
        $this->assertTrue(NotifyRunner::defers('comment', ['comment' => 'text']));
        config(['exment.notify_mode' => NotifyRunner::SYNC]);
        $this->assertFalse(NotifyRunner::defers('updated', []));
    }

    /**
     * Save a record of custom_value_edit_all with a notification to user1 and user2 (admin saves).
     *
     * @return array{got: array<int, string>, before: array<int, string>, subjects: array<int, string>, notify: Notify}
     */
    protected function bells(string $mode, string $name): array
    {
        config(['exment.notify_mode' => $mode]);
        $userModel = getModelName(SystemTableName::USER);
        $byCode = function ($code) use ($userModel): CustomValue {
            return $userModel::withoutGlobalScopes()->where('value->user_code', $code)->first();
        };
        $actor = $byCode('admin');
        $user1 = $byCode('user1');
        $user2 = $byCode('user2');

        $table = CustomTable::getEloquent('custom_value_edit_all');
        $notify = new Notify();
        $notify->notify_view_name = $name;
        $notify->target_id = $table->id;
        $notify->notify_trigger = NotifyTrigger::CREATE_UPDATE_DATA;
        $notify->mail_template_id = 6;
        $notify->active_flg = 1;
        $notify->trigger_settings = ['notify_saved_trigger' => ['created']];
        $notify->action_settings = [[
            'notify_action' => NotifyAction::SHOW_PAGE,
            'notify_action_target' => [NotifyActionTarget::FIXED_USER],
            'target_users' => [$user1->id, $user2->id],
        ]];
        $notify->save();
        System::clearCache();

        \Auth::guard('admin')->login(LoginUser::where('base_user_id', $actor->id)->first());
        $table->getValueModel()->setValue('text', $name)->save();

        $before = $this->namesOf($notify);
        if ($mode === NotifyRunner::AFTER_RESPONSE) {
            // the answer is sent: the terminating callbacks run
            app()->terminate();
        }

        return [
            'got' => $this->namesOf($notify),
            'before' => $before,
            'subjects' => NotifyNavbar::withoutGlobalScopes()->where('notify_id', $notify->id)->orderBy('target_user_id')->pluck('notify_subject')
                // without the name of the record and the numbers (its id, the time)
                ->map(fn ($subject) => preg_replace('/[0-9]+/', '#', str_replace($name, '', strval($subject))))->all(),
            'notify' => $notify,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function namesOf(Notify $notify): array
    {
        $userModel = getModelName(SystemTableName::USER);
        return NotifyNavbar::withoutGlobalScopes()->where('notify_id', $notify->id)->pluck('target_user_id')
            ->map(function ($id) use ($userModel) {
                $user = $userModel::withoutGlobalScopes()->find($id);
                return $user ? strval($user->getValue('user_code')) : strval($id);
            })->sort()->values()->all();
    }
}
