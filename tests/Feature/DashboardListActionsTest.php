<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\DashboardBoxItems\ListItem;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The data list box of a dashboard puts its action column on the left in the modern design,
 * as the data list page does (#2273); in the classic design it stays on the right.
 */
class DashboardListActionsTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function design(string $design): void
    {
        System::ui_design($design);
        System::clearCache();
        if (\Exment::getUiDesignForced() !== null) {
            $this->markTestSkipped('the design is fixed by the server (EXMENT_UI_DESIGN)');
        }
    }

    public function testTheActionsMoveFirstInTheModernDesign(): void
    {
        $this->design('modern');
        [$headers, $bodies, $styles, $classes] = ListItem::actionsFirst(
            ['A', 'B', 'action'],
            [['a1', 'b1', 'links1'], ['a2', 'b2', 'links2']],
            ['width:1px', ''],
            ['column-a', 'column-b']
        );
        $this->assertSame(['action', 'A', 'B'], $headers);
        $this->assertSame([['links1', 'a1', 'b1'], ['links2', 'a2', 'b2']], $bodies);
        $this->assertSame(['', 'width:1px', ''], $styles);
        $this->assertSame(['column-__actions__', 'column-a', 'column-b'], $classes);

        // no action column (a summary view): nothing moves
        $this->assertSame([['A', 'B'], [['a', 'b']], ['', ''], ['column-a', 'column-b']], ListItem::actionsFirst(['A', 'B'], [['a', 'b']], ['', ''], ['column-a', 'column-b']));
    }

    public function testTheActionsStayLastInTheClassicDesign(): void
    {
        $this->design('classic');
        $this->assertSame([['A', 'action'], [['a', 'links']], [''], ['column-a']], ListItem::actionsFirst(['A', 'action'], [['a', 'links']], [''], ['column-a']));
    }

    public function testTheBoxShowsTheActionsFirst(): void
    {
        $this->design('modern');
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $this->assertNotNull($table);
        $view = CustomView::getAllData($table);

        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::USER;
        $dashboard->dashboard_name = 'list_actions_' . short_uuid();
        $dashboard->dashboard_view_name = 'list actions';
        $dashboard->created_user_id = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->base_user_id;
        $dashboard->options = ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();
        $box = new DashboardBox();
        $box->dashboard_id = $dashboard->id;
        $box->dashboard_box_type = DashboardBoxType::LIST;
        $box->dashboard_box_view_name = 'list actions box';
        $box->row_no = 1;
        $box->column_no = 1;
        $box->options = ['target_table_id' => $table->id, 'target_view_id' => $view->id];
        $box->save();

        System::clearRequestSession();
        $response = $this->get(admin_urls('dashboardbox', 'html', $box->suuid), ['X-Requested-With' => 'XMLHttpRequest']);
        $response->assertOk();
        $json = json_decode((string) $response->getContent(), true);
        $html = is_array($json) ? strval($json['body'] ?? '') : (string) $response->getContent();
        $this->assertMatchesRegularExpression('#<th[^>]*column-__actions__[^>]*>\s*' . preg_quote(trans('admin.action'), '#') . '#u', $html);
        // the first header is the actions
        $this->assertSame(1, preg_match('#<th[^>]*>(.*?)</th>#su', $html, $first));
        $this->assertSame(trans('admin.action'), trim(strip_tags(strval($first[1] ?? ''))));
    }
}
