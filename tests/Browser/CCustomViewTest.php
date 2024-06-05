<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Traits\ColumnOptionQueryTrait;

class CCustomViewTest extends ExmentKitTestCase
{
    use ExmentKitPrepareTrait;
    use ColumnOptionQueryTrait;

    /**
     * pre-excecute process before test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
    }

    /**
     * prepare test table.
     */
    public function testPrepareTestTable()
    {
        $this->createCustomTable('exmenttest_view');
    }

    /**
     * prepare test columns.
     */
    public function testPrepareTestColumn()
    {
        $targets = ['integer', 'text', 'datetime', 'select', 'boolean', 'yesno', 'image', 'user_single'];
        $this->createCustomColumns('exmenttest_view', $targets);
    }

    /**
     * Check custom view display.
     */
    public function testDisplayViewSetting()
    {
        // Check custom column view
        $this->visit(admin_url('view/exmenttest_view'))
            ->seePageIs(admin_url('view/exmenttest_view'))
            ->see('カスタムビュー設定')
            ->seeInElement('th', 'ビュー表示名')
            ->seeInElement('th', 'ビュー権限')
            ->seeInElement('th', 'ビュー種類')
            ->seeInElement('th', '操作')
            ->visit(admin_url('view/exmenttest_view/create?view_kind_type=0'))
            ->seeInElement('h1', 'カスタムビュー設定')
            ->seeInElement('label', 'テーブル名(英数字)')
            ->seeInElement('label', 'テーブル表示名')
            ->seeInElement('label', 'ビュー種類')
            ->seeInElement('h4[class=field-header]', '表示列選択')
            ->seeInElement('h4[class=field-header]', 'データ表示条件')
            ->seeInElement('h4[class=field-header]', 'データ並べ替え')
            ->seeInElement('button[id=admin-submit]', '保存')
        ;
    }

    /**
     * Create custom view.
     */
    public function testAddViewSuccess()
    {
        $pre_cnt = CustomView::count();

        // Create custom view
        $this->visit(admin_url('view/exmenttest_view/create'))
            ->type('新しいビュー', 'view_view_name')
            ->press('admin-submit')
            ->seePageIs(admin_url('view/exmenttest_view'))
            ->seeInElement('td', '新しいビュー')
            ->assertEquals($pre_cnt + 1, CustomView::count())
        ;

        $raw = CustomView::orderBy('id', 'desc')->first();
        $id = array_get($raw, 'id');

        Model\System::clearRequestSession();

        // Update custom view
        $this->visit(admin_url('view/exmenttest_view/'. $id . '/edit'))
            ->seeInField('view_view_name', '新しいビュー')
            ->type('更新したビュー', 'view_view_name')
            ->press('admin-submit')
            ->seePageIs(admin_url('view/exmenttest_view'))
            ->seeInElement('td', '更新したビュー');
    }



    /**
     * Create custom view contains field.
     */
    public function testAddViewSuccessContainsField()
    {
        $pre_cnt = CustomView::count();
        $custom_table = CustomTable::getEloquent('exmenttest_view');
        $custom_column_text = CustomColumn::getEloquent('onelinetext', $custom_table);
        $custom_column_user = CustomColumn::getEloquent('user_single', $custom_table);

        $data = [
            'custom_table_id' => $custom_table->id,
            'view_kind_type' => 0,
            'view_view_name' => 'TestView2',
            'view_type' => 0,
            'default_flg' => 0,
            'pager_count' => 0,

            'custom_view_columns' => [
                'new_1' => [
                    'view_column_target' => "{$custom_column_text->id}?table_id={$custom_table->id}",
                    'view_column_name' => null,
                    'order' => 0,
                    '_remove_' => 0,
                ],
                'new_2' => [
                    'view_column_target' => "updated_at?table_id={$custom_table->id}",
                    'view_column_name' => null,
                    'order' => 0,
                    '_remove_' => 0,
                ],
            ],
            'custom_view_filters' => [
                'new_1' => [
                    'view_column_target' => "{$custom_column_text->id}?table_id={$custom_table->id}",
                    'view_filter_condition' => 1,
                    'view_filter_condition_value' => 'test',
                    'view_filter_condition_value_query' => 'test',
                    'order' => 0,
                    '_remove_' => 0,
                ],
                'new_2' => [
                    'view_column_target' => "{$custom_column_user->id}?table_id={$custom_table->id}",
                    'view_filter_condition' => 2001,
                    'view_filter_condition_value' => ["1"],
                    'view_filter_condition_value_query' => '["1"]',
                    'order' => 0,
                    '_remove_' => 0,
                ],
            ],
            'custom_view_sorts' => [
                'new_1' => [
                    'view_column_target' => "id?table_id={$custom_table->id}",
                    'sort' => -1,
                    'priority' => 1,
                    'order' => 0,
                    '_remove_' => 0,
                ],
                'new_2' => [
                    'view_column_target' => "{$custom_column_text->id}?table_id={$custom_table->id}",
                    'sort' => 1,
                    'priority' => 2,
                    'order' => 0,
                    '_remove_' => 0,
                ],
            ],
        ];

        $this->post(admin_url('view/exmenttest_view'), $data);
        $this->assertPostResponse($this->response, admin_url('view/exmenttest_view'));

        $this->visit(admin_url('view/exmenttest_view'))
            ->seePageIs(admin_url('view/exmenttest_view'))
            ->seeInElement('td', 'TestView2')
            ->assertEquals($pre_cnt + 1, CustomView::count())
        ;

        $raw = CustomView::orderBy('id', 'desc')->first();
        $id = array_get($raw, 'id');


        // Whether saving columns.
        $params = [
            'custom_view_columns' => ['classname' => Model\CustomViewColumn::class],
            'custom_view_filters' => ['classname' => Model\CustomViewFilter::class, 'callback' => function ($query, $v) {
                $query->where('view_filter_condition', $v['view_filter_condition'])
                    ->whereOrIn('view_filter_condition_value_text', $v['view_filter_condition_value_query'])
                ;
            }],
            'custom_view_sorts' => ['classname' => Model\CustomViewSort::class, 'callback' => function ($query, $v) {
                $query->where('sort', $v['sort'])
                    ->where('priority', $v['priority'])
                ;
            }],
        ];


        foreach ($params as $key => $value) {
            $classname = $value['classname'];
            $columns = array_get($data, $key, []);

            foreach ($columns as $k => $v) {
                // get column info from view_column_target
                list($column_type, $column_table_id, $column_type_target, $view_pivot_column_id, $view_pivot_table_id) = $this->getViewColumnTargetItems($v['view_column_target']);

                $query = $classname::query()
                    ->where('custom_view_id', $id)
                    ->where('view_column_type', $column_type)
                    ->where('view_column_target_id', $column_type_target)
                    ->where('view_column_table_id', $column_table_id)
                    ->withoutGlobalScopes();

                // if has query callback, execute, and filtering
                if (array_has($value, 'callback')) {
                    $callback = $value['callback'];
                    $callback($query, $v);
                }

                $this->assertTrue($query->exists(), "custom view items not contains items.　custom_view_id:{$id}, k:{$k}, key:{$key}, view_target:{$v['view_column_target']}, view_column_type:{$column_type}, view_column_target_id:{$column_type_target}, view_column_table_id:{$column_table_id}");
            }
        }
    }
}
