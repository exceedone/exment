<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\ShareTargetType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\UserSetting;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The share dialog of a user dashboard (admin/dashboard/{id}/sendShares) and the chart toolbar
 * state a viewer keeps per chart box (admin/dashboardbox/chart_state/{suuid}).
 */
class DashboardShareChartCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    public function testSendSharesStoresWhoMayEditTheDashboard(): void
    {
        $dashboard = $this->userDashboard();
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);

        $this->post(admin_url("dashboard/{$dashboard->id}/sendShares"), [
            Permission::DATA_SHARE_EDIT => ['user_' . $user1->base_user_id],
        ])->assertOk()->assertJson(['result' => true, 'toastr' => trans('admin.save_succeeded')]);

        $this->assertDatabaseHas(SystemTableName::DATA_SHARE_AUTHORITABLE, [
            'parent_type' => ShareTargetType::DASHBOARD,
            'parent_id' => $dashboard->id,
            'authoritable_type' => Permission::DATA_SHARE_EDIT,
            'authoritable_user_org_type' => SystemTableName::USER,
            'authoritable_target_id' => $user1->base_user_id,
        ]);

        // the dialog sends the whole list: a user left out is no longer shared
        $this->post(admin_url("dashboard/{$dashboard->id}/sendShares"), [
            Permission::DATA_SHARE_EDIT => [''],
        ])->assertOk()->assertJson(['result' => true]);
        $this->assertDatabaseMissing(SystemTableName::DATA_SHARE_AUTHORITABLE, [
            'parent_type' => ShareTargetType::DASHBOARD,
            'parent_id' => $dashboard->id,
        ]);
    }

    public function testChartStateKeepsTheToolbarChoiceOfTheViewer(): void
    {
        $dashboard = $this->userDashboard();
        $box = new DashboardBox();
        $box->dashboard_id = $dashboard->id;
        $box->dashboard_box_type = DashboardBoxType::CHART;
        $box->dashboard_box_view_name = 'claude chart box';
        $box->row_no = 1;
        $box->column_no = 1;
        $box->options = [];
        $box->save();
        $key = "dashboard_chart.{$dashboard->suuid}.{$box->suuid}";

        // sorted by the measure, descending
        $this->post(admin_url("dashboardbox/chart_state/{$box->suuid}"), ['cs' => 'y:desc'])
            ->assertOk()
            ->assertExactJson(['status' => true]);
        $this->assertSame(['cs' => 'y:desc'], array_get($this->settingsOfAdmin(), $key));

        // nothing chosen any more: the kept state goes too
        $this->post(admin_url("dashboardbox/chart_state/{$box->suuid}"), ['cs' => 'unknown'])
            ->assertOk()
            ->assertExactJson(['status' => true]);
        $this->assertNull(array_get($this->settingsOfAdmin(), $key));
    }

    protected function userDashboard(): Dashboard
    {
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::USER;
        $dashboard->dashboard_name = 'claude_cov_' . short_uuid();
        $dashboard->dashboard_view_name = 'claude coverage dashboard';
        $dashboard->created_user_id = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->base_user_id;
        $dashboard->options = ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();

        return $dashboard;
    }

    /**
     * @return array<mixed>
     */
    protected function settingsOfAdmin(): array
    {
        $base_user_id = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->base_user_id;

        return (array) UserSetting::where('base_user_id', $base_user_id)->firstOrFail()->settings;
    }
}
