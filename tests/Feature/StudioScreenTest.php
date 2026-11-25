<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Studio\ScreenList;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Studio, phase 1 (ISS00231): the screens of the site on one page.
 */
class StudioScreenTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function openStudio(LoginUser $user)
    {
        System::clearRequestSession();
        $this->be($user);
        return $this->get(admin_url('studio'), ['X-Requested-With' => 'XMLHttpRequest']);
    }

    public function testTheAdminSeesTheFormsAndViewsOfATableWithTheirLinks(): void
    {
        $response = $this->openStudio(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $response->assertOk();
        $response->assertSee(exmtrans('studio.header'));

        $table = CustomTable::getEloquent('parent_table');
        $this->assertNotNull($table);
        $response->assertSee($table->table_view_name);

        $form = CustomForm::where('custom_table_id', $table->id)->firstOrFail();
        $response->assertSee(admin_urls('form', 'parent_table', $form->id, 'edit'), false);
        $response->assertSee(admin_urls('form', 'parent_table', 'create?copy_id=' . $form->id), false);

        $view = CustomView::where('custom_table_id', $table->id)->where('view_type', ViewType::SYSTEM)->firstOrFail();
        $response->assertSee(admin_urls('view', 'parent_table', $view->id, 'edit'), false);

        // the relation diagram draws parent_table: it has child tables
        $response->assertSee('exm-studio-node', false);
    }

    public function testAUserSeesOnlyTheTablesTheyMaySet(): void
    {
        $checked = 0;
        $ids = [
            TestDefine::TESTDATA_USER_LOGINID_USER1,
            TestDefine::TESTDATA_USER_LOGINID_USER2,
            TestDefine::TESTDATA_USER_LOGINID_DEV_USERB,
            TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC,
        ];
        foreach ($ids as $id) {
            $user = LoginUser::findOrFail($id);
            $this->be($user);
            System::clearRequestSession();
            if ($user->hasPermission('system')) {
                continue;
            }
            $allowed = $user->allHasPermissionTables(ScreenList::TABLE_PERMISSIONS)->pluck('table_name')->all();

            $response = $this->openStudio($user);
            if (count($allowed) === 0) {
                $response->assertForbidden();
                $checked++;
                continue;
            }

            $response->assertOk();
            foreach (CustomTable::where('showlist_flg', true)->get() as $table) {
                // the attribute starts with the table names, then the names of its screens
                $card = 'data-studio-name="' . e(mb_strtolower($table->table_view_name . ' ' . $table->table_name)) . ' ';
                if (in_array($table->table_name, $allowed, true)) {
                    $response->assertSee($card, false);
                } else {
                    $response->assertDontSee($card, false);
                }
            }
            $checked++;
        }
        $this->assertGreaterThan(0, $checked);
    }
}
