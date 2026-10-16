<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Smoke test for the regression checklist (D:/tmp/v7_checklist/PLAN.md): GET every admin
 * screen route extracted from the route providers (out/ops.json) and make sure it does not
 * blow up with a 5xx. 200/302/403/404 are fine here (403/404 just mean missing sample data
 * or permission, not a route failure); only 5xx counts as broken.
 *
 * Routes with an unresolved parameter other than {tableKey}/{id}, a dynamic expression in
 * the URI, or outside the "admin" prefix (OAuth, etc.) are skipped - see out/smoke_skipped.txt.
 * Routes that end in $response->send() + exit (QR creation, the exports, the plugin batch) are left
 * out as well: exit would end the PHPUnit process (the first CI run died after 7 tests).
 * The requests are sent as XMLHttpRequest: for a missing record or a denied table,
 * Permission::error() then answers 403 instead of printing the error page and calling exit.
 */
class AdminScreensSmokeTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    /**
     * @dataProvider adminScreenUriProvider
     */
    public function testAdminScreenDoesNotReturnServerError(string $path): void
    {
        $response = $this->get(admin_url($path), ['X-Requested-With' => 'XMLHttpRequest']);
        $status = $response->getStatusCode();
        $e = $response->exceptions?->first();
        $reason = $status >= 500 && $e
            ? ' ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
            : '';
        $this->assertTrue($status < 500, "GET admin/$path -> $status$reason");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function adminScreenUriProvider(): array
    {
        return [
            'admin' => [''],
            'admin/api/data/{tableKey}/relatedLinkage' => ['api/data/custom_value_edit_all/relatedLinkage'],
            'admin/api/data/{tableKey}/select' => ['api/data/custom_value_edit_all/select'],
            'admin/api/data/{tableKey}/{id}' => ['api/data/custom_value_edit_all/1'],
            'admin/api_setting' => ['api_setting'],
            'admin/api_setting/create' => ['api_setting/create'],
            'admin/api_setting/{id}' => ['api_setting/1'],
            'admin/api_setting/{id}/edit' => ['api_setting/1/edit'],
            'admin/assign-jan-code' => ['assign-jan-code'],
            'admin/auth-2factor' => ['auth-2factor'],
            'admin/auth-2factor/google/register' => ['auth-2factor/google/register'],
            'admin/auth-2factor/google/sendmail' => ['auth-2factor/google/sendmail'],
            'admin/auth-2factor/logout' => ['auth-2factor/logout'],
            'admin/auth/change' => ['auth/change'],
            'admin/auth/file/background' => ['auth/file/background'],
            'admin/auth/file/header' => ['auth/file/header'],
            'admin/auth/forget' => ['auth/forget'],
            'admin/auth/login' => ['auth/login'],
            'admin/auth/logout' => ['auth/logout'],
            'admin/auth/logs' => ['auth/logs'],
            'admin/auth/logs/create' => ['auth/logs/create'],
            'admin/auth/logs/{id}' => ['auth/logs/1'],
            'admin/auth/logs/{id}/edit' => ['auth/logs/1/edit'],
            'admin/auth/menu' => ['auth/menu'],
            'admin/auth/menu/create' => ['auth/menu/create'],
            'admin/auth/menu/{id}' => ['auth/menu/1'],
            'admin/auth/menu/{id}/edit' => ['auth/menu/1/edit'],
            'admin/auth/setting' => ['auth/setting'],
            'admin/backup' => ['backup'],
            'admin/backup/importModal' => ['backup/importModal'],
            'admin/cell_style_preset' => ['cell_style_preset'],
            'admin/cell_style_preset/create' => ['cell_style_preset/create'],
            'admin/cell_style_preset/{id}' => ['cell_style_preset/1'],
            'admin/cell_style_preset/{id}/edit' => ['cell_style_preset/1/edit'],
            'admin/column/{tableKey}' => ['column/custom_value_edit_all'],
            'admin/column/{tableKey}/columnTypeHtml' => ['column/custom_value_edit_all/columnTypeHtml'],
            'admin/column/{tableKey}/create' => ['column/custom_value_edit_all/create'],
            'admin/column/{tableKey}/{id}' => ['column/custom_value_edit_all/1'],
            'admin/column/{tableKey}/{id}/columnTypeHtml' => ['column/custom_value_edit_all/1/columnTypeHtml'],
            'admin/column/{tableKey}/{id}/edit' => ['column/custom_value_edit_all/1/edit'],
            'admin/copy/{tableKey}' => ['copy/custom_value_edit_all'],
            'admin/copy/{tableKey}/create' => ['copy/custom_value_edit_all/create'],
            'admin/copy/{tableKey}/newModal' => ['copy/custom_value_edit_all/newModal'],
            'admin/copy/{tableKey}/{id}' => ['copy/custom_value_edit_all/1'],
            'admin/copy/{tableKey}/{id}/edit' => ['copy/custom_value_edit_all/1/edit'],
            'admin/dashboard' => ['dashboard'],
            'admin/dashboard/create' => ['dashboard/create'],
            'admin/dashboard/filter_bar_columns' => ['dashboard/filter_bar_columns'],
            'admin/dashboard/filter_bar_values' => ['dashboard/filter_bar_values'],
            'admin/dashboard/{id}' => ['dashboard/1'],
            'admin/dashboard/{id}/edit' => ['dashboard/1/edit'],
            'admin/dashboard/{id}/shareClick' => ['dashboard/1/shareClick'],
            'admin/dashboardbox' => ['dashboardbox'],
            'admin/dashboardbox/chart_filter_columns' => ['dashboardbox/chart_filter_columns'],
            'admin/dashboardbox/create' => ['dashboardbox/create'],
            'admin/dashboardbox/{id}' => ['dashboardbox/1'],
            'admin/dashboardbox/{id}/edit' => ['dashboardbox/1/edit'],
            'admin/data/{tableKey}' => ['data/custom_value_edit_all'],
            'admin/data/{tableKey}/create' => ['data/custom_value_edit_all/create'],
            'admin/data/{tableKey}/importModal' => ['data/custom_value_edit_all/importModal'],
            'admin/data/{tableKey}/kanbanCards' => ['data/custom_value_edit_all/kanbanCards'],
            'admin/data/{tableKey}/operationModal' => ['data/custom_value_edit_all/operationModal'],
            'admin/data/{tableKey}/{id}' => ['data/custom_value_edit_all/1'],
            'admin/data/{tableKey}/{id}/actionModal' => ['data/custom_value_edit_all/1/actionModal'],
            'admin/data/{tableKey}/{id}/compare' => ['data/custom_value_edit_all/1/compare'],
            'admin/data/{tableKey}/{id}/compareitem' => ['data/custom_value_edit_all/1/compareitem'],
            'admin/data/{tableKey}/{id}/copyModal' => ['data/custom_value_edit_all/1/copyModal'],
            'admin/data/{tableKey}/{id}/edit' => ['data/custom_value_edit_all/1/edit'],
            'admin/data/{tableKey}/{id}/kanbanHistory' => ['data/custom_value_edit_all/1/kanbanHistory'],
            'admin/data/{tableKey}/{id}/notifyClick' => ['data/custom_value_edit_all/1/notifyClick'],
            'admin/data/{tableKey}/{id}/operationModal' => ['data/custom_value_edit_all/1/operationModal'],
            'admin/data/{tableKey}/{id}/restoreClick' => ['data/custom_value_edit_all/1/restoreClick'],
            'admin/data/{tableKey}/{id}/shareClick' => ['data/custom_value_edit_all/1/shareClick'],
            'admin/data/{tableKey}/{id}/workflowHistoryModal' => ['data/custom_value_edit_all/1/workflowHistoryModal'],
            'admin/favicon' => ['favicon'],
            'admin/form/{tableKey}' => ['form/custom_value_edit_all'],
            'admin/form/{tableKey}/create' => ['form/custom_value_edit_all/create'],
            'admin/form/{tableKey}/preview' => ['form/custom_value_edit_all/preview'],
            'admin/form/{tableKey}/{id}' => ['form/custom_value_edit_all/1'],
            'admin/form/{tableKey}/{id}/edit' => ['form/custom_value_edit_all/1/edit'],
            'admin/formpriority/{tableKey}' => ['formpriority/custom_value_edit_all'],
            'admin/formpriority/{tableKey}/create' => ['formpriority/custom_value_edit_all/create'],
            'admin/formpriority/{tableKey}/{id}' => ['formpriority/custom_value_edit_all/1'],
            'admin/formpriority/{tableKey}/{id}/edit' => ['formpriority/custom_value_edit_all/1/edit'],
            'admin/formpublic/{tableKey}' => ['formpublic/custom_value_edit_all'],
            'admin/formpublic/{tableKey}/create' => ['formpublic/custom_value_edit_all/create'],
            'admin/formpublic/{tableKey}/notify_action_target' => ['formpublic/custom_value_edit_all/notify_action_target'],
            'admin/formpublic/{tableKey}/{id}' => ['formpublic/custom_value_edit_all/1'],
            'admin/formpublic/{tableKey}/{id}/activeModal' => ['formpublic/custom_value_edit_all/1/activeModal'],
            'admin/formpublic/{tableKey}/{id}/edit' => ['formpublic/custom_value_edit_all/1/edit'],
            'admin/initialize' => ['initialize'],
            'admin/install' => ['install'],
            'admin/install/reset' => ['install/reset'],
            'admin/jan-code/table/{id}' => ['jan-code/table/1'],
            'admin/jan-code/{id}' => ['jan-code/1'],
            'admin/line/link' => ['line/link'],
            'admin/login_setting' => ['login_setting'],
            'admin/login_setting/create' => ['login_setting/create'],
            'admin/login_setting/loginOptionHtml' => ['login_setting/loginOptionHtml'],
            'admin/login_setting/{id}' => ['login_setting/1'],
            'admin/login_setting/{id}/edit' => ['login_setting/1/edit'],
            'admin/login_setting/{id}/testModal' => ['login_setting/1/testModal'],
            'admin/login_setting/{id}/testSso' => ['login_setting/1/testSso'],
            'admin/login_setting/{id}/testcallback' => ['login_setting/1/testcallback'],
            'admin/loginuser' => ['loginuser'],
            'admin/loginuser/create' => ['loginuser/create'],
            'admin/loginuser/importModal' => ['loginuser/importModal'],
            'admin/loginuser/{id}' => ['loginuser/1'],
            'admin/loginuser/{id}/edit' => ['loginuser/1/edit'],
            'admin/meili-dictionary' => ['meili-dictionary'],
            'admin/meili-dictionary/create' => ['meili-dictionary/create'],
            'admin/meili-dictionary/{id}' => ['meili-dictionary/1'],
            'admin/meili-dictionary/{id}/edit' => ['meili-dictionary/1/edit'],
            'admin/meili-filter' => ['meili-filter'],
            'admin/meili-filter/columns' => ['meili-filter/columns'],
            'admin/meili-filter/create' => ['meili-filter/create'],
            'admin/meili-filter/{id}' => ['meili-filter/1'],
            'admin/meili-filter/{id}/edit' => ['meili-filter/1/edit'],
            'admin/notify' => ['notify'],
            'admin/notify/{tableKey}' => ['notify/custom_value_edit_all'],
            'admin/notify/{tableKey}/create' => ['notify/custom_value_edit_all/create'],
            'admin/notify/{tableKey}/notify_action_target' => ['notify/custom_value_edit_all/notify_action_target'],
            'admin/notify/{tableKey}/{id}' => ['notify/custom_value_edit_all/1'],
            'admin/notify/{tableKey}/{id}/edit' => ['notify/custom_value_edit_all/1/edit'],
            'admin/notify_navbar' => ['notify_navbar'],
            'admin/notify_navbar/create' => ['notify_navbar/create'],
            'admin/notify_navbar/rowdetail/{id}' => ['notify_navbar/rowdetail/1'],
            'admin/notify_navbar/{id}' => ['notify_navbar/1'],
            'admin/notify_navbar/{id}/edit' => ['notify_navbar/1/edit'],
            'admin/operation/{tableKey}' => ['operation/custom_value_edit_all'],
            'admin/operation/{tableKey}/create' => ['operation/custom_value_edit_all/create'],
            'admin/operation/{tableKey}/filter-value' => ['operation/custom_value_edit_all/filter-value'],
            'admin/operation/{tableKey}/{id}' => ['operation/custom_value_edit_all/1'],
            'admin/operation/{tableKey}/{id}/edit' => ['operation/custom_value_edit_all/1/edit'],
            'admin/plugin' => ['plugin'],
            'admin/plugin/create' => ['plugin/create'],
            'admin/plugin/edit_code/{id}' => ['plugin/edit_code/1'],
            'admin/plugin/edit_code/{id}/getTree' => ['plugin/edit_code/1/getTree'],
            'admin/plugin/edit_code/{id}/selectFile' => ['plugin/edit_code/1/selectFile'],
            'admin/plugin/{id}' => ['plugin/1'],
            'admin/plugin/{id}/edit' => ['plugin/1/edit'],
            'admin/relation/{tableKey}' => ['relation/custom_value_edit_all'],
            'admin/relation/{tableKey}/create' => ['relation/custom_value_edit_all/create'],
            'admin/relation/{tableKey}/{id}' => ['relation/custom_value_edit_all/1'],
            'admin/relation/{tableKey}/{id}/edit' => ['relation/custom_value_edit_all/1/edit'],
            'admin/role' => ['role'],
            'admin/role_group' => ['role_group'],
            'admin/role_group/create' => ['role_group/create'],
            'admin/role_group/importModal' => ['role_group/importModal'],
            'admin/role_group/{id}' => ['role_group/1'],
            'admin/role_group/{id}/edit' => ['role_group/1/edit'],
            'admin/safety/answer' => ['safety/answer'],
            'admin/safety_check' => ['safety_check'],
            'admin/saml/logout' => ['saml/logout'],
            'admin/search' => ['search'],
            'admin/search/header' => ['search/header'],
            'admin/search/list' => ['search/list'],
            'admin/search/lists' => ['search/lists'],
            'admin/search/relation' => ['search/relation'],
            'admin/search/saved/{id}/apply' => ['search/saved/1/apply'],
            'admin/system' => ['system'],
            'admin/table' => ['table'],
            'admin/table/create' => ['table/create'],
            'admin/table/menuModal/{id}' => ['table/menuModal/1'],
            'admin/table/{id}' => ['table/1'],
            'admin/table/{id}/copyModal' => ['table/1/copyModal'],
            'admin/table/{id}/edit' => ['table/1/edit'],
            'admin/template' => ['template'],
            'admin/template/import' => ['template/import'],
            'admin/view/{tableKey}' => ['view/custom_value_edit_all'],
            'admin/view/{tableKey}/create' => ['view/custom_value_edit_all/create'],
            'admin/view/{tableKey}/filter-condition' => ['view/custom_value_edit_all/filter-condition'],
            'admin/view/{tableKey}/group-condition' => ['view/custom_value_edit_all/group-condition'],
            'admin/view/{tableKey}/preview' => ['view/custom_value_edit_all/preview'],
            'admin/view/{tableKey}/summary-condition' => ['view/custom_value_edit_all/summary-condition'],
            'admin/view/{tableKey}/{id}' => ['view/custom_value_edit_all/1'],
            'admin/view/{tableKey}/{id}/edit' => ['view/custom_value_edit_all/1/edit'],
            'admin/view/{tableKey}/{id}/shareClick' => ['view/custom_value_edit_all/1/shareClick'],
            'admin/webapi/mention/users' => ['webapi/mention/users'],
            'admin/webapi/menu/menutargetview' => ['webapi/menu/menutargetview'],
            'admin/webapi/menu/menutype' => ['webapi/menu/menutype'],
            'admin/webapi/{tableKey}/cellstylepreset' => ['webapi/custom_value_edit_all/cellstylepreset'],
            'admin/webapi/{tableKey}/cellstylepreset/modal' => ['webapi/custom_value_edit_all/cellstylepreset/modal'],
            'admin/webapi/{tableKey}/filter-condition' => ['webapi/custom_value_edit_all/filter-condition'],
            'admin/webapi/{tableKey}/filter-value' => ['webapi/custom_value_edit_all/filter-value'],
            'admin/webapi/{tableKey}/operation-filter-value' => ['webapi/custom_value_edit_all/operation-filter-value'],
            'admin/webapi/{tableKey}/operation-update-type' => ['webapi/custom_value_edit_all/operation-update-type'],
            'admin/workflow' => ['workflow'],
            'admin/workflow/beginning' => ['workflow/beginning'],
            'admin/workflow/create' => ['workflow/create'],
            'admin/workflow/design/create' => ['workflow/design/create'],
            'admin/workflow/{id}' => ['workflow/1'],
            'admin/workflow/{id}/activateModal' => ['workflow/1/activateModal'],
            'admin/workflow/{id}/deactivateModal' => ['workflow/1/deactivateModal'],
            'admin/workflow/{id}/design' => ['workflow/1/design'],
            'admin/workflow/{id}/edit' => ['workflow/1/edit'],
            'admin/workflow_task' => ['workflow_task'],
            'admin/workflow_task/read' => ['workflow_task/read'],
        ];
    }
}
