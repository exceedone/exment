<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;

/**
 * FIXED (dashboard setting form, filter bar):
 * - the source table offered the tables the user may DEFINE: a user dashboard's owner, who
 *   may read a table but not define it, could not pick it
 * - two items on one column were saved, the second one silently dropped
 * - a form coming back with a validation error showed every item's column blank (choices of
 *   the stored table, not the posted one): the next save dropped the items
 * - deleting a box left its suuid among an item's targets: the item narrowed no box, until
 *   an unrelated save of the setting made it narrow every box
 *
 * Data rolled back; LoginUser 1 (system admin) and the first login user without the system
 * permission.
 */
class DashboardSettingFixedTest extends SecurityRegressionTestCase
{
    public function test_the_source_table_lists_the_tables_the_user_may_read(): void
    {
        $this->asViewer();
        System::userdashboard_available(true);
        $readable = CustomTable::filterList(null, ['permissions' => Permission::AVAILABLE_VIEW_CUSTOM_VALUE])->pluck('table_name');
        $readOnly = $readable->diff(CustomTable::filterList()->pluck('table_name'))->values();
        if ($readOnly->isEmpty()) {
            $this->markTestSkipped('needs a table the viewer may read but not define');
        }

        $html = (string) $this->get($this->url('dashboard/create'))->getContent();

        foreach ($readOnly as $tableName) {
            $this->assertMatchesRegularExpression('/<option value="' . preg_quote($tableName, '/') . '"/', $html, "$tableName is offered");
        }
    }

    public function test_two_items_on_one_column_are_refused(): void
    {
        $this->asUser(LoginUser::find(1));
        $name = 'dup_' . uniqid();

        $response = $this->post($this->url('dashboard'), $this->dashboardForm($name, [
            ['column' => 'user_name', 'label' => 'first'],
            ['column' => 'user_name', 'label' => 'second'],
        ]));

        $response->assertSessionHasErrors();
        $this->assertSame(0, Dashboard::withoutGlobalScopes()->where('dashboard_name', $name)->count());

        // a removed row does not count
        $this->post($this->url('dashboard'), $this->dashboardForm($name, [
            ['column' => 'user_name', 'label' => 'first'],
            ['column' => 'user_name', 'label' => 'second', '_remove_' => 1],
        ]));
        $saved = Dashboard::withoutGlobalScopes()->where('dashboard_name', $name)->firstOrFail();
        $this->assertSame(['user_name'], array_column($saved->getOption('filter_bar.dims'), 'column'));
    }

    public function test_two_items_on_one_column_are_refused_on_edit(): void
    {
        // an existing dashboard has chart boxes: an item posting its targets (a multiple
        // select) made the items table validate only the array cells, the column rules skipped
        $this->asUser(LoginUser::find(1));
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::SYSTEM;
        $dashboard->dashboard_name = 'dup_edit_' . uniqid();
        $dashboard->dashboard_view_name = 'dup edit';
        $dashboard->options = ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();
        $box = $this->chartBox($dashboard, 1);
        $dashboard->setOption('filter_bar', ['source_table' => 'user', 'dims' => [
            ['column' => 'user_name', 'label' => 'first'],
        ]]);
        $dashboard->save();

        $form = $this->dashboardForm($dashboard->dashboard_name, [
            ['column' => 'user_name', 'label' => 'first', 'targets' => [$box->suuid, '']],
            ['column' => 'user_name', 'label' => 'second', 'targets' => ['']],
        ]);
        unset($form['dashboard_name'], $form['dashboard_type']);
        $response = $this->put($this->url('dashboard/' . $dashboard->id), $form);

        $response->assertSessionHasErrors('filter_bar_dims.new_2.column');
        $dims = Dashboard::withoutGlobalScopes()->where('id', $dashboard->id)->firstOrFail()->getOption('filter_bar.dims');
        $this->assertSame(['first'], array_column($dims, 'label'), 'nothing saved');

        // a column the table does not have is refused too (the same rules were skipped)
        $form['filter_bar_dims'] = ['new_1' => ['column' => 'no_such_column', 'label' => 'x', 'targets' => [$box->suuid, ''], 'default' => '', '_remove_' => 0]];
        $this->put($this->url('dashboard/' . $dashboard->id), $form)->assertSessionHasErrors('filter_bar_dims.new_1.column');

        // the targets cell is still checked against the dashboard's boxes
        $form['filter_bar_dims'] = ['new_1' => ['column' => 'user_name', 'label' => 'x', 'targets' => ['not_a_box', ''], 'default' => '', '_remove_' => 0]];
        $this->put($this->url('dashboard/' . $dashboard->id), $form)->assertSessionHasErrors();

        // a valid edit still saves, its targets kept
        $form['filter_bar_dims'] = ['new_1' => ['column' => 'user_name', 'label' => 'edited', 'targets' => [$box->suuid, ''], 'default' => '', '_remove_' => 0]];
        $this->put($this->url('dashboard/' . $dashboard->id), $form)->assertSessionHasNoErrors();
        $dims = Dashboard::withoutGlobalScopes()->where('id', $dashboard->id)->firstOrFail()->getOption('filter_bar.dims');
        $this->assertSame('edited', $dims[0]['label']);
        $this->assertSame([$box->suuid], $dims[0]['targets']);
    }

    public function test_a_form_back_with_an_error_keeps_the_posted_columns(): void
    {
        $this->asUser(LoginUser::find(1));
        // the duplicate makes the save fail; the form comes back with the posted values
        $this->post($this->url('dashboard'), $this->dashboardForm('back_' . uniqid(), [
            ['column' => 'user_name', 'label' => 'a'],
            ['column' => 'user_name', 'label' => 'b'],
        ]))->assertSessionHasErrors();

        $html = (string) $this->get($this->url('dashboard/create'))->getContent();

        $this->assertMatchesRegularExpression('/<option value="user_name"[^>]*selected/', $html, 'the posted column is chosen again');
    }

    public function test_deleting_a_box_forgets_it_among_the_filter_targets(): void
    {
        $this->asUser(LoginUser::find(1));
        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::SYSTEM;
        $dashboard->dashboard_name = 'targets_' . uniqid();
        $dashboard->dashboard_view_name = 'targets';
        $dashboard->options = ['row1' => 2, 'row2' => 0, 'row3' => 0, 'row4' => 0];
        $dashboard->save();
        [$x, $y] = [$this->chartBox($dashboard, 1), $this->chartBox($dashboard, 2)];
        $dashboard->setOption('filter_bar', ['source_table' => 'user', 'dims' => [
            ['column' => 'user_name', 'label' => 'only x', 'targets' => [$x->suuid]],
            ['column' => 'email', 'label' => 'x and y', 'targets' => [$x->suuid, $y->suuid]],
        ]]);
        $dashboard->save();

        $this->delete($this->url('dashboardbox/delete/' . $x->suuid))->assertOk();

        $dims = Dashboard::withoutGlobalScopes()->where('id', $dashboard->id)->firstOrFail()->getOption('filter_bar.dims');
        $this->assertArrayNotHasKey('targets', $dims[0], 'its only target gone: every box, as its form shows it');
        $this->assertSame([$y->suuid], $dims[1]['targets']);
    }

    private function chartBox(Dashboard $dashboard, int $column): DashboardBox
    {
        $box = new DashboardBox();
        $box->dashboard_id = $dashboard->id;
        $box->dashboard_box_type = DashboardBoxType::CHART;
        $box->dashboard_box_view_name = 'box ' . $column;
        $box->row_no = 1;
        $box->column_no = $column;
        $box->options = [];
        $box->save();
        return $box;
    }

    /**
     * @param array<int, array<string, mixed>> $dims
     * @return array<string, mixed>
     */
    private function dashboardForm(string $name, array $dims): array
    {
        $rows = [];
        foreach ($dims as $i => $dim) {
            $rows['new_' . ($i + 1)] = $dim + ['default' => '', '_remove_' => 0];
        }
        return [
            'dashboard_name' => $name, 'dashboard_view_name' => $name, 'dashboard_type' => DashboardType::SYSTEM,
            'row_setting' => ['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0],
            'filter_bar_table' => 'user', 'filter_bar_dims' => $rows,
        ];
    }

    private function asViewer(): void
    {
        foreach (LoginUser::all() as $login) {
            if ($login->base_user === null) {
                continue;
            }
            $this->asUser($login);
            if (!\Exment::user()->hasPermission(Permission::SYSTEM)) {
                return;
            }
        }
        $this->markTestSkipped('needs a login user without the system permission');
    }

    private function asUser(?LoginUser $login): void
    {
        if ($login === null) {
            $this->markTestSkipped('needs LoginUser 1 in the dev DB');
        }
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->actingAs($login, 'admin')->withoutMiddleware(VerifyCsrfToken::class);
    }

    private function url(string $path): string
    {
        return '/' . config('admin.route.prefix', 'admin') . '/' . $path;
    }
}
