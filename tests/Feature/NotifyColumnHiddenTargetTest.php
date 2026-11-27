<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\JoinedMultiUserFilterType;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Notify\NotifyRunner;
use Exceedone\Exment\Services\Notify\NotifyTargetBase;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Collection;

/**
 * A notification to a user or organization column, when the user who saves the record cannot
 * see the user in it (filter_multi_user).
 *
 * The column's value then reads as the "no permission" text for the one who saves, and the
 * notification passed that text on as a user: a TypeError, and the save failed. The users a
 * notification goes to are now the ones stored in the record, whoever saved it. A notify button
 * lists its targets to the login user, so there they are still only the ones they can see.
 */
class NotifyColumnHiddenTargetTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const TABLE = TestDefine::TESTDATA_TABLE_NAME_EDIT;

    protected function setUp(): void
    {
        parent::setUp();
        config(['exment.notify_mode' => NotifyRunner::SYNC]);
    }

    protected function tearDown(): void
    {
        config(['exment.notify_mode' => 'auto']);
        System::clearCache();
        System::clearRequestSession();
        parent::tearDown();
    }

    public function testCreateNotifiesTheAssigneeTheSaverCannotSee(): void
    {
        $notify = $this->createNotify(NotifyTrigger::CREATE_UPDATE_DATA, ['notify_saved_trigger' => ['created']]);
        [, $hidden] = $this->loginAsSaverWithHiddenUser();

        // the case under test: the saver reads the assignee as "no permission"
        $probe = CustomTable::getEloquent(self::TABLE)->getValueModel();
        $probe->setValue('user', $hidden->id);
        $this->assertSame(exmtrans('common.message.no_permission'), $probe->getValue('user'));

        $value = CustomTable::getEloquent(self::TABLE)->getValueModel();
        $value->setValue(['text' => 'notify hidden assignee', 'user' => $hidden->id]);
        $value->save();

        $this->assertNotNull($value->id, 'the save succeeds');
        $bells = NotifyNavbar::withoutGlobalScopes()
            ->where('notify_id', $notify->id)
            ->where('parent_id', $value->id)
            ->pluck('target_user_id')
            ->map(fn ($id) => intval($id))
            ->all();
        $this->assertContains(intval($hidden->id), $bells, 'the assignee is told, though the saver cannot see them');
    }

    public function testButtonListsOnlyTheTargetsTheLoginUserCanSee(): void
    {
        $notify = $this->createNotify(NotifyTrigger::BUTTON, ['notify_button_name' => 'hidden assignee']);
        [, $hidden] = $this->loginAsSaverWithHiddenUser();

        $value = CustomTable::getEloquent(self::TABLE)->getValueModel();
        $value->setValue(['text' => 'button hidden assignee', 'user' => $hidden->id]);
        $value->save();

        $targets = $this->columnTargets($notify, $value);

        $this->assertNotContains(intval($hidden->id), $targets, 'not listed to one who cannot see them');
    }

    public function testCreateNotifiesAVisibleAssigneeAsBefore(): void
    {
        $notify = $this->createNotify(NotifyTrigger::CREATE_UPDATE_DATA, ['notify_saved_trigger' => ['created']]);
        [$saver] = $this->loginAsSaverWithHiddenUser();

        $value = CustomTable::getEloquent(self::TABLE)->getValueModel();
        $value->setValue(['text' => 'notify visible assignee', 'user' => $saver->base_user_id]);
        $value->save();

        $this->assertSame([intval($saver->base_user_id)], $this->columnTargets($notify, $value));

        // and the button, for the same user
        $button = $this->createNotify(NotifyTrigger::BUTTON, ['notify_button_name' => 'visible assignee']);
        $this->assertSame([intval($saver->base_user_id)], $this->columnTargets($button, $value));
    }

    public function testOrganizationMembersTheLoginUserCannotSee(): void
    {
        $organizationOf = function (CustomValue $user) {
            $pivot = CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER);
            $id = \DB::table($pivot)->where('child_id', $user->id)->orderBy('parent_id')->value('parent_id');
            return is_null($id) ? null : getModelName(SystemTableName::ORGANIZATION)::withoutGlobalScope(CustomValueModelScope::class)->find($id);
        };
        // a user the saver cannot see, in an organization
        [, $hidden] = $this->loginAsSaverWithHiddenUser(function (CustomValue $user) use ($organizationOf) {
            return !is_null($organizationOf($user));
        });
        $organization = $organizationOf($hidden);

        $ids = function (Collection $targets): array {
            return $targets->map(fn ($target) => intval($target->id()))->all();
        };
        $this->assertContains(intval($hidden->id), $ids(NotifyTarget::getModelsAsOrganization($organization, null, true)));
        $this->assertNotContains(intval($hidden->id), $ids(NotifyTarget::getModelsAsOrganization($organization)));
        $this->assertCount(0, NotifyTarget::getModelsAsOrganization(null));
    }

    public function testTargetValuesReadTheStoredIds(): void
    {
        [, $hidden] = $this->loginAsSaverWithHiddenUser();
        $userTable = CustomTable::getEloquent(SystemTableName::USER);

        $read = function ($ids) use ($userTable) {
            return NotifyTarget::getTargetValues($userTable, $ids)->map(fn ($v) => intval($v->id))->all();
        };
        $this->assertSame([intval($hidden->id)], $read($hidden->id));
        $this->assertSame([intval($hidden->id)], $read(strval($hidden->id)));
        $this->assertSame([intval($hidden->id)], $read([$hidden->id, null, '']));
        $this->assertSame([intval($hidden->id)], $read('[' . $hidden->id . ']'));
        $this->assertSame([], $read(null));
        $this->assertSame([], $read(exmtrans('common.message.no_permission')));
    }

    // ---- fixtures -----------------------------------------------------------

    /**
     * @param string $trigger
     * @param array<string, mixed> $triggerSettings
     */
    private function createNotify(string $trigger, array $triggerSettings): Notify
    {
        $table = CustomTable::getEloquent(self::TABLE);
        $column = CustomColumn::getEloquent('user', $table);
        $this->assertNotNull($column, 'Fixture: a user column');

        $notify = new Notify();
        $notify->notify_view_name = 'notify_column_hidden_' . $trigger;
        $notify->target_id = $table->id;
        $notify->notify_trigger = $trigger;
        $notify->mail_template_id = 6;
        $notify->active_flg = 1;
        $notify->trigger_settings = $triggerSettings;
        $notify->action_settings = [[
            'notify_action' => NotifyAction::SHOW_PAGE,
            'notify_action_target' => [strval($column->id)],
        ]];
        $notify->save();
        System::clearCache();

        return $notify;
    }

    /**
     * Turn filter_multi_user on and log in as a user who cannot see some other user.
     *
     * @param callable|null $accept which of the users the saver cannot see will do
     * @return array{0: LoginUser, 1: CustomValue} the saver, a user they cannot see
     */
    private function loginAsSaverWithHiddenUser(?callable $accept = null): array
    {
        System::filter_multi_user(JoinedMultiUserFilterType::ONLY_JOIN);
        System::clearCache();

        $userModel = getModelName(SystemTableName::USER);
        foreach ([
            TestDefine::TESTDATA_USER_LOGINID_DEV1_USERD,
            TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC,
            TestDefine::TESTDATA_USER_LOGINID_DEV_USERB,
            TestDefine::TESTDATA_USER_LOGINID_USER2,
            TestDefine::TESTDATA_USER_LOGINID_USER1,
        ] as $loginId) {
            $saver = LoginUser::find($loginId);
            if (is_null($saver)) {
                continue;
            }
            $this->be($saver, 'admin');
            System::clearRequestSession();

            $visible = $userModel::query()->pluck('id')->all();
            $hidden = $userModel::withoutGlobalScope(CustomValueModelScope::class)
                ->whereNotIn('id', $visible)
                ->orderBy('id')
                ->get()
                ->first(function ($user) use ($accept) {
                    return is_null($accept) || $accept($user);
                });
            if (!is_null($hidden)) {
                return [$saver, $hidden];
            }
        }

        $this->fail('Fixture: a user who cannot see another user with filter_multi_user on');
    }

    /**
     * The users the column target of the notify gives, as the login user.
     *
     * @return array<int, int>
     */
    private function columnTargets(Notify $notify, CustomValue $value): array
    {
        $action_setting = $notify->action_settings[0];
        $target = NotifyTargetBase::make($action_setting['notify_action_target'][0], $notify, $action_setting);
        $this->assertNotNull($target);

        return $target->getModels($value, $value->custom_table)
            ->filter()
            ->map(fn ($item) => intval($item->id()))
            ->values()
            ->all();
    }
}
