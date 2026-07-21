<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Trang tự liên kết LINE (admin/line/link) phải mở được cho MỌI user đã đăng nhập,
 * không riêng system admin — mỗi user chỉ thao tác trên liên kết của chính mình
 * nên không có leo thang quyền.
 *
 * Chặn 2 tầng cùng engine Permission::shouldPass('line'):
 * - middleware admin.permission trên route (403 nếu fail)
 * - Admin::user()->visible() ẩn menu sidebar
 */
class LineLinkPageTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    /** Đăng nhập 1 user theo guard admin, xoá liên kết cũ để trang hiện form sinh QR. */
    protected function loginAndReset(string $loginUserId): LoginUser
    {
        /** @var LoginUser $loginUser */
        $loginUser = LoginUser::find($loginUserId);
        $this->assertNotNull($loginUser, "Fixture: cần login user {$loginUserId}.");
        LineAccountLink::where('user_id', $loginUser->base_user_id)->delete();
        $this->be($loginUser, 'admin');
        return $loginUser;
    }

    /** User thường (user2 - chỉ thuộc user_group) phải mở được trang link. */
    public function testEmployeeCanOpenLineLinkPage()
    {
        $this->loginAndReset(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $response = $this->get('admin/line/link');

        $response->assertStatus(200);
        // marker của view line.link (form sinh QR) - trang deny không có chuỗi này
        $response->assertSee('line/link/generate');
    }

    /** Menu sidebar: visible('line/link') phải true với user thường. */
    public function testLineLinkMenuVisibleForEmployee()
    {
        $loginUser = $this->loginAndReset(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $this->assertTrue(
            $loginUser->visible('line/link'),
            'Menu LINE連携 phải hiển thị với user thường (không phải system admin).'
        );
    }

    /** Hồi quy: admin vẫn mở được trang link như trước. */
    public function testAdminCanOpenLineLinkPage()
    {
        $this->loginAndReset(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $response = $this->get('admin/line/link');

        $response->assertStatus(200);
        $response->assertSee('line/link/generate');
    }
}
