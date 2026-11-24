<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/**
 * #1668: the system alert goes to each user, even when users have the same mail address.
 */
class NotifyAlertPerUserTest extends UnitTestBase
{
    use DatabaseTransactions;

    /**
     * Users: admin creates the data, user1 and user2 are the fixed targets.
     *
     * @param string $case different | same_as_actor | same_pair | actor_in_targets
     * @param bool $notifyMyself
     * @return array<int, string> who got the alert
     */
    protected function alertTargets(string $case, bool $notifyMyself = false): array
    {
        $userModel = getModelName(SystemTableName::USER);
        $byCode = function ($code) use ($userModel): CustomValue {
            return $userModel::withoutGlobalScopes()->where('value->user_code', $code)->first();
        };
        $actor = $byCode('admin');
        $user1 = $byCode('user1');
        $user2 = $byCode('user2');

        $setEmail = function (CustomValue $user, $email) {
            DB::table(getDBTableName(SystemTableName::USER))->where('id', $user->id)
                ->update(['value' => DB::raw("JSON_SET(value, '$.email', " . DB::getPdo()->quote($email) . ")")]);
        };
        if ($case === 'same_as_actor') {
            $setEmail($user1, $actor->getValue('email'));
        } elseif ($case === 'same_pair') {
            $setEmail($user1, $user2->getValue('email'));
        }
        $targets = $case === 'actor_in_targets' ? [$actor->id, $user2->id] : [$user1->id, $user2->id];

        $table = CustomTable::getEloquent('custom_value_edit_all');
        $notify = new Notify();
        $notify->notify_view_name = 'notify_alert_per_user';
        $notify->target_id = $table->id;
        $notify->notify_trigger = NotifyTrigger::CREATE_UPDATE_DATA;
        $notify->mail_template_id = 6;
        $notify->active_flg = 1;
        $notify->trigger_settings = ['notify_saved_trigger' => ['created'], 'notify_myself' => $notifyMyself];
        $notify->action_settings = [[
            'notify_action' => NotifyAction::SHOW_PAGE,
            'notify_action_target' => [NotifyActionTarget::FIXED_USER],
            'target_users' => $targets,
        ]];
        $notify->save();
        System::clearCache();

        \Illuminate\Support\Facades\Auth::guard('admin')->login(LoginUser::where('base_user_id', $actor->id)->first());
        $table->getValueModel()->setValue('text', 'notify_alert_per_user')->save();

        $names = [$actor->id => 'admin', $user1->id => 'user1', $user2->id => 'user2'];
        $got = NotifyNavbar::withoutGlobalScopes()->where('notify_id', $notify->id)->pluck('target_user_id')
            ->map(function ($id) use ($names) {
                return $names[$id] ?? (string)$id;
            })->sort()->values()->all();
        return $got;
    }

    public function testDifferentAddresses(): void
    {
        $this->assertSame(['user1', 'user2'], $this->alertTargets('different'));
    }

    public function testTargetHasTheSameAddressAsTheActor(): void
    {
        // before the fix, user1 was skipped as "the user who did it"
        $this->assertSame(['user1', 'user2'], $this->alertTargets('same_as_actor'));
    }

    public function testTargetsHaveTheSameAddress(): void
    {
        // before the fix, only one of them got the alert
        $this->assertSame(['user1', 'user2'], $this->alertTargets('same_pair'));
    }

    public function testTheActorDoesNotGetIt(): void
    {
        $this->assertSame(['user2'], $this->alertTargets('actor_in_targets'));
    }

    public function testTheActorGetsItWithNotifyMyself(): void
    {
        $this->assertSame(['admin', 'user2'], $this->alertTargets('actor_in_targets', true));
    }
}
