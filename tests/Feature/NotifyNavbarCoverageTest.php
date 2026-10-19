<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The buttons of the notification list (admin/notify_navbar): mark the ticked rows read, the
 * read all / unread all / delete all menu, the row delete, and the update route of the resource.
 * NotifyNavbar's global scope keeps every one of them to the login user's own notifications.
 */
class NotifyNavbarCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    public function testRowCheckMarksTheTickedNotificationsRead(): void
    {
        $first = $this->notification(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $second = $this->notification(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $this->post(admin_url("notify_navbar/rowcheck/{$first->id},{$second->id}"))
            ->assertOk()
            ->assertJson(['result' => true, 'toastr' => exmtrans('notify_navbar.message.check_succeeded')]);
        $this->assertDatabaseHas(SystemTableName::NOTIFY_NAVBAR, ['id' => $first->id, 'read_flg' => 1]);
        $this->assertDatabaseHas(SystemTableName::NOTIFY_NAVBAR, ['id' => $second->id, 'read_flg' => 1]);

        // read already: nothing left to mark
        $this->post(admin_url("notify_navbar/rowcheck/{$first->id}"))
            ->assertStatus(400)
            ->assertJson(['result' => false, 'toastr' => exmtrans('notify_navbar.message.check_notfound')]);
    }

    public function testBatchAllTouchesOnlyTheOwnNotifications(): void
    {
        $own = $this->notification(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $others = $this->notification(TestDefine::TESTDATA_USER_LOGINID_USER1);

        $this->post(admin_url('notify_navbar/batchAll/read'))
            ->assertOk()
            ->assertJson(['result' => true, 'toastr' => exmtrans('notify_navbar.message.read_succeeded')]);
        $this->assertDatabaseHas(SystemTableName::NOTIFY_NAVBAR, ['id' => $own->id, 'read_flg' => 1]);
        $this->assertDatabaseHas(SystemTableName::NOTIFY_NAVBAR, ['id' => $others->id, 'read_flg' => 0]);

        $this->post(admin_url('notify_navbar/batchAll/unread'))
            ->assertOk()
            ->assertJson(['result' => true, 'toastr' => exmtrans('notify_navbar.message.unread_succeeded')]);
        $this->assertDatabaseHas(SystemTableName::NOTIFY_NAVBAR, ['id' => $own->id, 'read_flg' => 0]);

        $this->post(admin_url('notify_navbar/batchAll/delete'))
            ->assertOk()
            ->assertJson(['result' => true, 'toastr' => exmtrans('notify_navbar.message.delete_succeeded')]);
        $this->assertDatabaseMissing(SystemTableName::NOTIFY_NAVBAR, ['id' => $own->id]);
        $this->assertDatabaseHas(SystemTableName::NOTIFY_NAVBAR, ['id' => $others->id]);

        // anything else is not a batch
        $this->post(admin_url('notify_navbar/batchAll/archive'))
            ->assertStatus(400)
            ->assertJson(['result' => false, 'swaltext' => exmtrans('notify_navbar.message.batch_error')]);
        $this->assertDatabaseHas(SystemTableName::NOTIFY_NAVBAR, ['id' => $others->id]);
    }

    public function testUpdateLeavesTheNotificationAndDestroyDeletesIt(): void
    {
        $notify = $this->notification(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        // the resource form has no field (the list offers no edit), so an update writes nothing
        $this->put(admin_url("notify_navbar/{$notify->id}"), ['notify_subject' => 'claude changed', 'read_flg' => 1])
            ->assertRedirect(admin_url('notify_navbar'));
        $this->assertDatabaseHas(SystemTableName::NOTIFY_NAVBAR, [
            'id' => $notify->id,
            'notify_subject' => $notify->notify_subject,
            'read_flg' => 0,
        ]);

        $this->delete(admin_url("notify_navbar/{$notify->id}"))
            ->assertOk()
            ->assertJson(['status' => true]);
        $this->assertDatabaseMissing(SystemTableName::NOTIFY_NAVBAR, ['id' => $notify->id]);
    }

    /**
     * An unread notification addressed to a login user.
     */
    protected function notification(string $login_id): NotifyNavbar
    {
        $notify = new NotifyNavbar();
        $notify->notify_id = 1;
        $notify->target_user_id = LoginUser::findOrFail($login_id)->base_user_id;
        $notify->trigger_user_id = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->base_user_id;
        $notify->read_flg = false;
        $notify->notify_subject = 'claude navbar ' . short_uuid();
        $notify->notify_body = 'claude navbar body';
        $notify->save();

        return $notify;
    }
}
