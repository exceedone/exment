<?php

namespace Exceedone\Exment\Tests\Browser;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewGridFilter;
use Exceedone\Exment\Model\Traits\ColumnOptionQueryTrait;

class CCustomViewTest extends ExmentKitTestCase
{
    use ExmentKitPrepareTrait;
    use ColumnOptionQueryTrait;

    /**
     * pre-excecute process before test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->login();
    }

    /**
     * prepare test table.
     *
     * @return void
     */
    public function testPrepareTestTable()
    {
        $this->createCustomTable('exmenttest_view');
    }

    /**
     * prepare test columns.
     *
     * @return void
     */
    public function testPrepareTestColumn()
    {
        $targets = ['integer', 'text', 'datetime', 'select', 'boolean', 'yesno', 'image', 'user_single'];
        $this->createCustomColumns('exmenttest_view', $targets);
    }

    /**
     * Check custom view display.
     *
     * @return void
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
     *
     * @return void
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
     * Create custom view.
     *
     * @return void
     */
    public function testAddSummaryViewSuccess()
    {
        $pre_cnt = CustomView::count();
        $custom_table = CustomTable::getEloquent('custom_value_edit_all');

        $data = [
            'custom_table_id' => $custom_table->id,
            'view_kind_type' => 1,
            'view_view_name' => 'TestSummaryView',
            'view_type' => 0,
            'default_flg' => 0,
            'pager_count' => 0,

            'custom_view_columns' => [
                'new_1' => [
                    'view_column_target' => "workflow_status?table_id={$custom_table->id}",
                    'view_column_name' => null,
                    'order' => 0,
                    '_remove_' => 0,
                ]
            ],
            'custom_view_summaries' => [
                'new_1' => [
                    'view_column_target' => "id?table_id={$custom_table->id}",
                    'view_summary_condition' => 3,
                    '_remove_' => 0,
                ]
            ]
        ];

        $this->post(admin_url('view/custom_value_edit_all'), $data);
        $this->assertPostResponse($this->response, admin_url('view/custom_value_edit_all'));

        $this->visit(admin_url('view/custom_value_edit_all'))
            ->seePageIs(admin_url('view/custom_value_edit_all'))
            ->seeInElement('td', 'TestSummaryView')
            ->assertEquals($pre_cnt + 1, CustomView::count())
        ;

        $raw = CustomView::orderBy('id', 'desc')->first();
        $custom_view_column = $raw->custom_view_columns->first();
        $uniqueName = $custom_view_column->column_item->uniqueName();
        $params = [
            'group_view' => $raw->suuid,
            'group_key' => json_encode([$uniqueName => '1'])
        ];

        $url = admin_urls_query('data', 'custom_value_edit_all', $params);
        $this->visit($url)
            ->seeInElement('td', '1000')
        ;

        $this->delete(admin_url('view/custom_value_edit_all/' . $raw->id));
        $this->assertPostResponse($this->response, null);
    }


    /**
     * Create custom view contains field.
     *
     * @return void
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

    /**
     * check custom view grid filters initialized default.
     */
    public function testCheckDefaultGridFilter()
    {
        $custom_table = CustomTable::getEloquent('exmenttest_view');
        $raw = CustomView::where('custom_table_id', $custom_table->id)->where('view_view_name', 'TestView2')->first();
        $suuid = array_get($raw, 'suuid');

        $this->visit(admin_url("data/exmenttest_view?view={$suuid}"));

        $response = $this->get(admin_url("data/exmenttest_view?filter_ajax=1"));
        $content = $response->response->getContent();
        if (is_json($content)) {
            $json = json_decode_ex($content, true);
            $html = array_get($json, 'html');

            $pattern = '/<label\b[^>]*>\s*ID\s*<\/label>/i';
            $this->assertTrue(preg_match($pattern, $html) === 1);

            $pattern = '/<label\b[^>]*>\s*作成日時\s*<\/label>/i';
            $this->assertTrue(preg_match($pattern, $html) === 1);

            $pattern = '/<label\b[^>]*>\s*更新日時\s*<\/label>/i';
            $this->assertTrue(preg_match($pattern, $html) === 1);

            $pattern = '/<label\b[^>]*>\s*作成ユーザー\s*<\/label>/i';
            $this->assertTrue(preg_match($pattern, $html) === 1);

            $pattern = '/<label\b[^>]*>\s*更新ユーザー\s*<\/label>/i';
            $this->assertTrue(preg_match($pattern, $html) === 1);

            $pattern = '/<label\b[^>]*>\s*Integer\s*<\/label>/i';
            $this->assertTrue(preg_match($pattern, $html) === 1);

            $pattern = '/<label\b[^>]*>\s*One Line Text\s*<\/label>/i';
            $this->assertTrue(preg_match($pattern, $html) === 1);

            $pattern = '/<label\b[^>]*>\s*Date and Time\s*<\/label>/i';
            $this->assertTrue(preg_match($pattern, $html) === 1);

            $pattern = '/<label\b[^>]*>\s*Select From Static Value\s*<\/label>/i';
            $this->assertTrue(preg_match($pattern, $html) === 1);

            $pattern = '/<label\b[^>]*>\s*User Single\s*<\/label>/i';
            $this->assertTrue(preg_match($pattern, $html) === 1);
        }
    }

    /**
     * Add custom view grid filters.
     */
    public function testAddViewSuccessGridFilter()
    {
        $custom_table = CustomTable::getEloquent('exmenttest_view');
        $custom_column_text = CustomColumn::getEloquent('onelinetext', $custom_table);
        $custom_column_user = CustomColumn::getEloquent('user_single', $custom_table);

        $user_table = CustomTable::getEloquent('user');
        $custom_column_username = CustomColumn::getEloquent('user_name', $user_table);

        $raw = CustomView::where('custom_table_id', $custom_table->id)->where('view_view_name', 'TestView2')->first();
        $id = array_get($raw, 'id');
        $suuid = array_get($raw, 'suuid');

        $data = [
            'custom_view_grid_filters' => [
                'new_1' => [
                    'view_column_target' => "id?table_id={$custom_table->id}",
                    '_remove_' => 0,
                ],
                'new_2' => [
                    'view_column_target' => "{$custom_column_text->id}?table_id={$custom_table->id}",
                    '_remove_' => 0,
                ],
            ],
        ];

        $this->put(admin_url("view/exmenttest_view/{$id}"), $data);
        
        $this->visit(admin_url("data/exmenttest_view?view={$suuid}"));

        $response = $this->get(admin_url("data/exmenttest_view?filter_ajax=1"));
        $content = $response->response->getContent();
        if (is_json($content)) {
            $json = json_decode_ex($content, true);
            $html = array_get($json, 'html');

            $pattern = '/<label\b[^>]*>\s*ID\s*<\/label>/i';
            $this->assertTrue(preg_match($pattern, $html) === 1);

            $pattern = '/<label\b[^>]*>\s*One Line Text\s*<\/label>/i';
            $this->assertTrue(preg_match($pattern, $html) === 1);

            $pattern = '/<label\b[^>]*>\s*作成日時\s*<\/label>/i';
            $this->assertFalse(preg_match($pattern, $html) === 1);
        }
    }

    /**
     * Update custom view grid filters.
     */
    public function testUpdateViewSuccessGridFilter()
    {
        $custom_table = CustomTable::getEloquent('exmenttest_view');
        $custom_column_user = CustomColumn::getEloquent('user_single', $custom_table);

        $user_table = CustomTable::getEloquent('user');
        $custom_column_username = CustomColumn::getEloquent('user_name', $user_table);

        $raw = CustomView::where('custom_table_id', $custom_table->id)->where('view_view_name', 'TestView2')->first();
        $id = array_get($raw, 'id');
        $suuid = array_get($raw, 'suuid');

        $grid_filters = CustomViewGridFilter::where('custom_view_id', $id)->pluck('id');

        $data = [
            'custom_view_grid_filters' => [
                $grid_filters[0] => [
                    'view_column_target' => "updated_user?table_id={$custom_table->id}",
                    'id' => $grid_filters[0],
                    '_remove_' => 0,
                ],
                $grid_filters[1] => [
                    'view_column_target' => "{$custom_column_username->id}?table_id={$user_table->id}&view_pivot_column_id={$custom_column_user->id}&view_pivot_table_id={$custom_table->id}",
                    'id' => $grid_filters[1],
                    '_remove_' => 0,
                ],
            ],
        ];

        $this->put(admin_url("view/exmenttest_view/{$id}"), $data);
        
        $this->visit(admin_url("data/exmenttest_view?view={$suuid}"));

        $response = $this->get(admin_url("data/exmenttest_view?filter_ajax=1"));
        $content = $response->response->getContent();
        if (is_json($content)) {
            $json = json_decode_ex($content, true);
            $html = array_get($json, 'html');

            $pattern = '/<label\b[^>]*>\s*更新ユーザー\s*<\/label>/i';
            $this->assertTrue(preg_match($pattern, $html) === 1);

            $pattern = '/<label\b[^>]*>\s*User Single : ユーザー名\s*<\/label>/i';
            $this->assertTrue(preg_match($pattern, $html) === 1);

            $pattern = '/<label\b[^>]*>\s*One Line Text\s*<\/label>/i';
            $this->assertFalse(preg_match($pattern, $html) === 1);
        }

    }
}
