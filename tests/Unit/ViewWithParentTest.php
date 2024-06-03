<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ValueType;
use Exceedone\Exment\Enums\ViewColumnSort;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Encore\Admin\Grid;

/**
 * Custom view include parent or select table column test.
 */
class ViewWithParentTest extends TestCase
{
    use TestTrait;
    use CustomViewTrait;
    use DatabaseTransactions;

    protected function init()
    {
        $this->initAllTest();
//        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    /**
     * 1-1.
     * CustomViewColumn = parent : YES
     * CustomViewFilter = parent : YES
     */
    public function testFuncFilterParent()
    {
        $this->init();

        $options = $this->getOptions(
            ['parent_table.text', 'id', 'text', 'parent_table.id', 'parent_table.integer'],
            ['parent_table.text']
        );

        $filter_column = 'text';
        $filter_value = 'test_2';
        $options['filter_settings'][0]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][0]['filter_value_text'] = $filter_value;

        $array = $this->getColumnFilterData(function ($data, $custom_view) use ($filter_column, $filter_value) {
            if ($data instanceof CustomValue) {
                $parent = $data->getParentValue();
                return $parent?->getValue($filter_column) == $filter_value;
            } else {
                $column_item = $custom_view->custom_view_columns[0]->column_item;
                $unique_name = $column_item?->uniqueName();
                return array_get($data, $unique_name) == $filter_value;
            }
        }, $options);
    }

    /**
     * 1-2.
     * CustomViewColumn = parent : NO
     * CustomViewFilter = parent : YES
     */
    public function testFuncFilterParentNoColumn()
    {
        $this->init();

        $options = $this->getOptions(
            ['id', 'text'],
            ['parent_table.text']
        );
        $filter_column = 'text';
        $filter_value = 'test_3';
        $options['filter_settings'][0]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][0]['filter_value_text'] = $filter_value;

        $array = $this->getColumnFilterData(function ($data, $custom_view) use ($filter_column, $filter_value) {
            if (!($data instanceof CustomValue)) {
                $id = array_get($data, 'id');
                $data = getModelName(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE)::find($id);
            }
            $parent = $data?->getParentValue();
            return $parent?->getValue($filter_column) == $filter_value;
        }, $options);
    }

    /**
     * 1-3.
     * CustomViewColumn = parent : YES
     * CustomViewFilter = parent : YES (id)
     */
    public function testFuncFilterParentId()
    {
        $this->init();

        $options = $this->getOptions(
            ['parent_table.id', 'id', 'text', 'parent_table.text', 'parent_table.integer'],
            ['parent_table.id']
        );

        $filter_column = 'id';
        $filter_value = 1;
        $options['filter_settings'][0]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][0]['filter_value_text'] = $filter_value;

        $array = $this->getColumnFilterData(function ($data, $custom_view) use ($filter_column, $filter_value) {
            if ($data instanceof CustomValue) {
                $parent = $data->getParentValue();
                return $parent?->getValue($filter_column) == $filter_value;
            } else {
                $column_item = $custom_view->custom_view_columns[0]->column_item;
                $unique_name = $column_item?->uniqueName();
                return array_get($data, $unique_name) == $filter_value;
            }
        }, $options);
    }

    /**
     * 1-4.
     * CustomViewColumn = parent : YES
     * CustomViewFilter = parent : NO (id)
     */
    public function testFuncFilterParentIdNoColumn()
    {
        $this->init();

        $options = $this->getOptions(
            ['id', 'text'],
            ['parent_table.id']
        );

        $filter_column = 'id';
        $filter_value = 3;
        $options['filter_settings'][0]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][0]['filter_value_text'] = $filter_value;

        $array = $this->getColumnFilterData(function ($data, $custom_view) use ($filter_column, $filter_value) {
            if (!($data instanceof CustomValue)) {
                $id = array_get($data, 'id');
                $data = getModelName(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE)::find($id);
            }
            $parent = $data?->getParentValue();
            return array_get($parent, $filter_column) == $filter_value;
        }, $options);
    }

    /**
     * 1-5.
     * CustomViewColumn = parent : YES
     * CustomViewSort = parent : YES
     */
    public function testFuncSortByParent()
    {
        $this->init();

        $options = $this->getOptions(
            ['parent_table.index_text', 'id', 'text', 'parent_table.id', 'parent_table.integer'],
            [],
            ['parent_table.index_text']
        );

        $options['sort_settings'][0]['sort'] = ViewColumnSort::DESC;
        $options['sort_settings'][0]['priority'] = 1;

        $array = $this->getColumnFilterData(function ($prev_data, $data, $custom_view) {
            $column_item = $custom_view->custom_view_columns[0]->column_item;
            $unique_name = $column_item?->uniqueName();
            return array_get($prev_data, $unique_name) >= array_get($data, $unique_name);
        }, $options);
    }

    /**
     * 1-6.
     * CustomViewColumn = parent : NO
     * CustomViewSort = parent : YES
     */
    public function testFuncSortByParentNoColumn()
    {
        $this->init();

        $options = $this->getOptions(
            ['id', 'text'],
            [],
            ['parent_table.index_text']
        );

        $sort_column = 'index_text';
        $options['sort_settings'][0]['sort'] = ViewColumnSort::ASC;
        $options['sort_settings'][0]['priority'] = 1;

        $parent_table = getModelName(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);

        $array = $this->getColumnFilterData(function ($prev_data, $data, $custom_view) use ($sort_column, $parent_table) {
            $prev_parent = $parent_table::find(array_get($prev_data, 'parent_id'));
            $parent = $parent_table::find(array_get($data, 'parent_id'));
            return $prev_parent->getValue($sort_column) <= $parent?->getValue($sort_column);
        }, $options);
    }

    /**
     * 1-7.
     * CustomViewColumn = parent : YES
     * CustomViewSort = parent : YES(id)
     */
    public function testFuncSortByParentId()
    {
        $this->init();

        $options = $this->getOptions(
            ['parent_table.id', 'id', 'text', 'parent_table.text', 'parent_table.integer'],
            [],
            ['parent_table.id']
        );

        $options['sort_settings'][0]['sort'] = ViewColumnSort::ASC;
        $options['sort_settings'][0]['priority'] = 1;

        $array = $this->getColumnFilterData(function ($prev_data, $data, $custom_view) {
            $column_item = $custom_view->custom_view_columns[0]->column_item;
            $unique_name = $column_item?->uniqueName();
            return array_get($prev_data, $unique_name) <= array_get($data, $unique_name);
        }, $options);
    }

    /**
     * 1-8.
     * CustomViewColumn = parent : NO
     * CustomViewSort = parent : YES(id)
     */
    public function testFuncSortByParentIdNoColumn()
    {
        $this->init();

        $options = $this->getOptions(
            ['id', 'text'],
            [],
            ['parent_table.id']
        );

        $options['sort_settings'][0]['sort'] = ViewColumnSort::ASC;
        $options['sort_settings'][0]['priority'] = 1;

        $parent_table = getModelName(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);

        $array = $this->getColumnFilterData(function ($prev_data, $data, $custom_view) use ($parent_table) {
            $prev_parent = $parent_table::find(array_get($prev_data, 'parent_id'));
            $parent = $parent_table::find(array_get($data, 'parent_id'));
            return $prev_parent->id <= $parent->id;
        }, $options);
    }

    /**
     * 2-1.
     * CustomViewColumn = select_table : YES
     * CustomViewFilter = select_table : YES
     */
    public function testFuncFilterSelect()
    {
        $this->init();

        $options = $this->getOptions(
            ['custom_value_view_all.index_text.select_table', 'id', 'text', 'custom_value_view_all.id.select_table', 'custom_value_view_all.integer.select_table'],
            ['custom_value_view_all.index_text.select_table']
        );

        $options['target_table_name'] = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        $filter_column = 'index_text';
        $filter_value = 'index_001_007';
        $options['filter_settings'][0]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][0]['filter_value_text'] = $filter_value;

        $array = $this->getColumnFilterData(function ($data, $custom_view) use ($filter_column, $filter_value) {
            if ($data instanceof CustomValue) {
                $select_table = $data->getValue('select_table');
                return $select_table?->getValue($filter_column) == $filter_value;
            } else {
                $column_item = $custom_view->custom_view_columns[0]->column_item;
                $unique_name = $column_item?->uniqueName();
                return array_get($data, $unique_name) == $filter_value;
            }
        }, $options);
    }

    /**
     * 2-2.
     * CustomViewColumn = select_table : NO
     * CustomViewFilter = select_table : YES
     */
    public function testFuncFilterSelectNoColumn()
    {
        $this->init();

        $options = $this->getOptions(
            ['id', 'text'],
            ['custom_value_view_all.index_text.select_table']
        );

        $options['target_table_name'] = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        $filter_column = 'index_text';
        $filter_value = 'index_001_005';
        $options['filter_settings'][0]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][0]['filter_value_text'] = $filter_value;

        $array = $this->getColumnFilterData(function ($data, $custom_view) use ($filter_column, $filter_value) {
            if (!($data instanceof CustomValue)) {
                $id = array_get($data, 'id');
                $data = getModelName(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST)::find($id);
            }
            $select_table = $data?->getValue('select_table');
            return $select_table?->getValue($filter_column) == $filter_value;
        }, $options);
    }

    /**
     * 2-3.
     * CustomViewColumn = select_table : YES
     * CustomViewFilter = select_table : YES(id)
     */
    public function testFuncFilterSelectId()
    {
        $this->init();

        $options = $this->getOptions(
            ['custom_value_view_all.id.select_table', 'custom_value_view_all.index_text.select_table', 'id', 'text', 'custom_value_view_all.integer.select_table'],
            ['custom_value_view_all.id.select_table']
        );

        $options['target_table_name'] = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        $filter_value = 5;
        $options['filter_settings'][0]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][0]['filter_value_text'] = $filter_value;

        $array = $this->getColumnFilterData(function ($data, $custom_view) use ($filter_value) {
            if ($data instanceof CustomValue) {
                $select_table = $data->getValue('select_table');
                return array_get($select_table, 'id') == $filter_value;
            } else {
                $column_item = $custom_view->custom_view_columns[0]->column_item;
                $unique_name = $column_item?->uniqueName();
                return array_get($data, $unique_name) == $filter_value;
            }
        }, $options);
    }

    /**
     * 2-4.
     * CustomViewColumn = select_table : NO
     * CustomViewFilter = select_table : YES(id)
     */
    public function testFuncFilterSelectIdNoColumn()
    {
        $this->init();

        $options = $this->getOptions(
            ['id', 'text'],
            ['custom_value_view_all.id.select_table']
        );

        $options['target_table_name'] = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        $filter_column = 'id';
        $filter_value = 3;
        $options['filter_settings'][0]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][0]['filter_value_text'] = $filter_value;

        $array = $this->getColumnFilterData(function ($data, $custom_view) use ($filter_column, $filter_value) {
            if (!($data instanceof CustomValue)) {
                $id = array_get($data, 'id');
                $data = getModelName(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST)::find($id);
            }
            $select_table = $data?->getValue('select_table');
            return array_get($select_table, $filter_column) == $filter_value;
        }, $options);
    }

    /**
     * 2-5.
     * CustomViewColumn = select_table : YES
     * CustomViewSort = select_table : YES
     */
    public function testFuncSortBySelect()
    {
        $this->init();

        $options = $this->getOptions(
            ['custom_value_view_all.index_text.select_table', 'id', 'text', 'custom_value_view_all.id.select_table', 'custom_value_view_all.integer.select_table'],
            [],
            ['custom_value_view_all.index_text.select_table']
        );

        $options['target_table_name'] = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        $options['sort_settings'][0]['sort'] = ViewColumnSort::DESC;
        $options['sort_settings'][0]['priority'] = 1;

        $array = $this->getColumnFilterData(function ($prev_data, $data, $custom_view) {
            $column_item = $custom_view->custom_view_columns[0]->column_item;
            $unique_name = $column_item?->uniqueName();
            return array_get($prev_data, $unique_name) >= array_get($data, $unique_name);
        }, $options);
    }

    /**
     * 2-6.
     * CustomViewColumn = select_table : NO
     * CustomViewSort = select_table : YES
     */
    public function testFuncSortBySelectNoColumn()
    {
        $this->init();

        $options = $this->getOptions(
            ['id', 'text'],
            [],
            ['custom_value_view_all.index_text.select_table']
        );

        $sort_column = 'index_text';
        $options['target_table_name'] = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        $options['sort_settings'][0]['sort'] = ViewColumnSort::ASC;
        $options['sort_settings'][0]['priority'] = 1;

        $select_table = getModelName(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);

        $array = $this->getColumnFilterData(function ($prev_data, $data, $custom_view) use ($select_table, $sort_column) {
            $prev_select = $select_table::find(array_get($prev_data, 'value.select_table'));
            $select = $select_table::find(array_get($data, 'value.select_table'));
            return $prev_select?->getValue($sort_column) <= $select->getValue($sort_column);
        }, $options);
    }

    /**
     * 2-7.
     * CustomViewColumn = select_table : YES
     * CustomViewSort = select_table : YES(id)
     */
    public function testFuncSortBySelectId()
    {
        $this->init();

        $options = $this->getOptions(
            ['custom_value_view_all.id.select_table', 'custom_value_view_all.index_text.select_table', 'id', 'text', 'custom_value_view_all.integer.select_table'],
            [],
            ['custom_value_view_all.id.select_table']
        );

        $options['target_table_name'] = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        $options['sort_settings'][0]['sort'] = ViewColumnSort::ASC;
        $options['sort_settings'][0]['priority'] = 1;

        $array = $this->getColumnFilterData(function ($prev_data, $data, $custom_view) {
            $column_item = $custom_view->custom_view_columns[0]->column_item;
            $unique_name = $column_item?->uniqueName();
            return array_get($prev_data, $unique_name) <= array_get($data, $unique_name);
        }, $options);
    }

    /**
     * 2-8.
     * CustomViewColumn = select_table : NO
     * CustomViewSort = select_table : YES(id)
     */
    public function testFuncSortBySelectIdNoColumn()
    {
        $this->init();

        $options = $this->getOptions(
            ['id', 'text'],
            [],
            ['custom_value_view_all.id.select_table']
        );

        $sort_column = 'id';
        $options['target_table_name'] = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        $options['sort_settings'][0]['sort'] = ViewColumnSort::DESC;
        $options['sort_settings'][0]['priority'] = 1;

        $select_table = getModelName(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);

        $array = $this->getColumnFilterData(function ($prev_data, $data, $custom_view) use ($select_table, $sort_column) {
            $prev_select = $select_table::find(array_get($prev_data, 'value.select_table'));
            $select = $select_table::find(array_get($data, 'value.select_table'));
            return array_get($prev_select, $sort_column) >= array_get($select, $sort_column);
        }, $options);
    }

    /**
     * 3-1.
     * CustomViewColumn = parent : YES, user : YES
     * CustomViewFilter = parent : YES, user : YES
     */
    public function testFuncFilterParentUser()
    {
        $this->init();

        $options = $this->getOptions(
            ['parent_table.text', 'user.user_name.user', 'id', 'text', 'parent_table.id', 'parent_table.integer', 'user.id.user'],
            ['parent_table.text', 'user.user_name.user']
        );

        $options['filter_settings'][0]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][0]['filter_value_text'] = 'test_2';
        $options['filter_settings'][1]['filter_condition'] = FilterOption::LIKE;
        $options['filter_settings'][1]['filter_value_text'] = 'user';

        $array = $this->getColumnFilterData(function ($data, $custom_view) {
            if ($data instanceof CustomValue) {
                $parent = $data->getParentValue();
                if ($parent?->getValue('text') != 'test_2') {
                    return false;
                }
                $select = $data->getValue('user');
                return strpos($select?->getValue('user_name'), 'user') === 0;
            } else {
                $column_item = $custom_view->custom_view_columns[0]->column_item;
                $unique_name = $column_item?->uniqueName();
                $column_item_2 = $custom_view->custom_view_columns[1]->column_item;
                $unique_name_2 = $column_item_2->uniqueName();
                return array_get($data, $unique_name) == 'test_2'
                    && strpos(array_get($data, $unique_name_2), 'user') === 0;
            }
        }, $options);
    }

    /**
     * 3-2.
     * CustomViewColumn = parent : NO, user : NO
     * CustomViewFilter = parent : YES, user : YES
     */
    public function testFuncFilterParentUserNoColumn()
    {
        $this->init();

        $options = $this->getOptions(
            ['id', 'text'],
            ['parent_table.text', 'user.user_name.user']
        );

        $options['filter_settings'][0]['filter_condition'] = FilterOption::NE;
        $options['filter_settings'][0]['filter_value_text'] = 'test_2';
        $options['filter_settings'][1]['filter_condition'] = FilterOption::NOT_LIKE;
        $options['filter_settings'][1]['filter_value_text'] = 'user';

        $array = $this->getColumnFilterData(function ($data, $custom_view) {
            if (!($data instanceof CustomValue)) {
                $id = array_get($data, 'id');
                $data = getModelName(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE)::find($id);
            }
            $parent = $data?->getParentValue();
            $user = $data?->getValue('user');
            if (is_null($user)) {
                return false;
            }
            return strpos($user->getValue('user_name'), 'user') !== 0 &&
                $parent?->getValue('text') != 'test_2';
        }, $options);
    }

    /**
     * 3-3.
     * CustomViewColumn = parent : YES, user : YES
     * CustomViewFilter = parent : YES(id), user : YES(id)
     */
    public function testFuncFilterParentUserId()
    {
        $this->init();

        $options = $this->getOptions(
            ['parent_table.id', 'user.id.user', 'id', 'text', 'parent_table.text', 'parent_table.integer', 'user.user_name.user'],
            ['parent_table.id', 'user.id.user']
        );

        $options['filter_settings'][0]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][0]['filter_value_text'] = 2;
        $options['filter_settings'][1]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][1]['filter_value_text'] = 1;

        $array = $this->getColumnFilterData(function ($data, $custom_view) {
            if ($data instanceof CustomValue) {
                $parent = $data->getParentValue();
                $select = $data->getValue('user');
                return array_get($parent, 'id') == 2 && array_get($select, 'id') == 1;
            } else {
                $column_item = $custom_view->custom_view_columns[0]->column_item;
                $unique_name = $column_item?->uniqueName();
                $column_item_2 = $custom_view->custom_view_columns[1]->column_item;
                $unique_name_2 = $column_item_2->uniqueName();
                return array_get($data, $unique_name) == 2
                    && array_get($data, $unique_name_2) == 1;
            }
        }, $options);
    }

    /**
     * 3-4.
     * CustomViewColumn = parent : NO, user : NO
     * CustomViewFilter = parent : YES(id), user : YES(id)
     */
    public function testFuncFilterParentUserIdNoColumn()
    {
        $this->init();

        $options = $this->getOptions(
            ['id', 'text'],
            ['parent_table.id', 'user.id.user']
        );

        $options['filter_settings'][0]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][0]['filter_value_text'] = 1;
        $options['filter_settings'][1]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][1]['filter_value_text'] = 2;

        $array = $this->getColumnFilterData(function ($data, $custom_view) {
            if (!($data instanceof CustomValue)) {
                $id = array_get($data, 'id');
                $data = getModelName(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE)::find($id);
            }
            $parent = $data?->getParentValue();
            $user = $data?->getValue('user');
            return array_get($parent, 'id') == 1 && array_get($user, 'id') == 2;
        }, $options);
    }

    /**
     * 3-5.
     * CustomViewColumn = parent : YES, user : YES
     * CustomViewSort = parent : YES, user : YES
     */
    public function testFuncSortByParentUser()
    {
        $this->init();

        $options = $this->getOptions(
            ['parent_table.index_text', 'user.user_code.user', 'id', 'text', 'parent_table.id', 'parent_table.integer', 'user.id.user'],
            [],
            ['parent_table.index_text', 'user.user_code.user']
        );

        $options['sort_settings'][0]['sort'] = ViewColumnSort::ASC;
        $options['sort_settings'][0]['priority'] = 1;
        $options['sort_settings'][1]['sort'] = ViewColumnSort::DESC;
        $options['sort_settings'][1]['priority'] = 2;

        $array = $this->getColumnFilterData(function ($prev_data, $data, $custom_view) {
            $column_item = $custom_view->custom_view_columns[0]->column_item;
            $unique_name = $column_item?->uniqueName();
            $column_item_2 = $custom_view->custom_view_columns[1]->column_item;
            $unique_name_2 = $column_item_2->uniqueName();
            if (array_get($prev_data, $unique_name) < array_get($data, $unique_name)) {
                return true;
            }
            return array_get($prev_data, $unique_name) == array_get($data, $unique_name) &&
                array_get($prev_data, $unique_name_2) >= array_get($data, $unique_name_2);
        }, $options);
    }

    /**
     * 3-6.
     * CustomViewColumn = parent : NO, user : NO
     * CustomViewSort = parent : YES, user : YES
     */
    public function testFuncSortByParentUserNoColumn()
    {
        $this->init();

        $options = $this->getOptions(
            ['id', 'text'],
            [],
            ['parent_table.index_text', 'user.user_code.user']
        );

        $options['sort_settings'][0]['sort'] = ViewColumnSort::DESC;
        $options['sort_settings'][0]['priority'] = 1;
        $options['sort_settings'][1]['sort'] = ViewColumnSort::ASC;
        $options['sort_settings'][1]['priority'] = 2;

        $child_table = getModelName(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);

        $array = $this->getColumnFilterData(function ($prev_data, $data, $custom_view) use ($child_table) {
            if (!($data instanceof CustomValue)) {
                $data = $child_table::find(array_get($data, 'id'));
                $prev_data = $child_table::find(array_get($prev_data, 'id'));
            }
            $parent = $data?->getParentValue();
            $prev_parent = $prev_data?->getParentValue();
            $user = $data?->getValue('user');
            $prev_user = $prev_data?->getValue('user');

            if ($prev_parent->getValue('index_text') > $parent?->getValue('index_text')) {
                return true;
            }
            return $prev_parent->getValue('index_text') == $parent?->getValue('index_text') &&
                $prev_user->getValue('user_name') <= $user?->getValue('user_name');
        }, $options);
    }

    /**
     * 3-7.
     * CustomViewColumn = parent : YES, user : YES
     * CustomViewSort = parent : YES(id), user : YES(id)
     */
    public function testFuncSortByParentUserId()
    {
        $this->init();

        $options = $this->getOptions(
            ['parent_table.id', 'user.id.user', 'id', 'text', 'parent_table.text', 'parent_table.integer', 'user.user_name.user'],
            [],
            ['parent_table.id', 'user.id.user']
        );

        $options['sort_settings'][0]['sort'] = ViewColumnSort::ASC;
        $options['sort_settings'][0]['priority'] = 1;
        $options['sort_settings'][1]['sort'] = ViewColumnSort::DESC;
        $options['sort_settings'][1]['priority'] = 2;

        $array = $this->getColumnFilterData(function ($prev_data, $data, $custom_view) {
            $column_item = $custom_view->custom_view_columns[0]->column_item;
            $unique_name = $column_item?->uniqueName();
            $column_item_2 = $custom_view->custom_view_columns[1]->column_item;
            $unique_name_2 = $column_item_2->uniqueName();
            if (array_get($prev_data, $unique_name) < array_get($data, $unique_name)) {
                return true;
            }
            return array_get($prev_data, $unique_name) == array_get($data, $unique_name) &&
                array_get($prev_data, $unique_name_2) >= array_get($data, $unique_name_2);
        }, $options);
    }

    /**
     * 3-8.
     * CustomViewColumn = parent : YES, user : YES
     * CustomViewSort = parent : YES(id), user : YES(id)
     */
    public function testFuncSortByParentUserIdColumn()
    {
        $this->init();

        $options = $this->getOptions(
            ['id', 'text'],
            [],
            ['parent_table.id', 'user.id.user']
        );

        $options['sort_settings'][0]['sort'] = ViewColumnSort::DESC;
        $options['sort_settings'][0]['priority'] = 1;
        $options['sort_settings'][1]['sort'] = ViewColumnSort::ASC;
        $options['sort_settings'][1]['priority'] = 2;

        $child_table = getModelName(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);

        $array = $this->getColumnFilterData(function ($prev_data, $data, $custom_view) use ($child_table) {
            if (!($data instanceof CustomValue)) {
                $data = $child_table::find(array_get($data, 'id'));
                $prev_data = $child_table::find(array_get($prev_data, 'id'));
            }
            $parent = $data?->getParentValue();
            $prev_parent = $prev_data?->getParentValue();
            $user = $data?->getValue('user');
            $prev_user = $prev_data?->getValue('user');

            if (array_get($prev_parent, 'id') > array_get($parent, 'id')) {
                return true;
            }
            return array_get($prev_parent, 'id') == array_get($parent, 'id') &&
                array_get($prev_user, 'id') <= array_get($user, 'id');
        }, $options);
    }

    /**
     * 4-1.
     * CustomViewColumn = select_table : YES
     * CustomViewFilter = select_table : YES
     * (This table has multiple columns that reference the same table)
     */
    public function testFuncFilterSelectSameTable()
    {
        $this->init();

        $options = $this->getOptions(
            ['child_table.odd_even.child', 'child_table.odd_even.child_view', 'child_table.odd_even.child_ajax', 'id', 'parent_table.odd_even.parent'],
            ['child_table.odd_even.child', 'child_table.odd_even.child_view', 'child_table.odd_even.child_ajax']
        );

        $options['target_table_name'] = TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE;
        $options['filter_settings'][0]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][0]['filter_value_text'] = 'odd';
        $options['filter_settings'][1]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][1]['filter_value_text'] = 'even';
        $options['filter_settings'][2]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][2]['filter_value_text'] = 'odd';

        $array = $this->getColumnFilterData(function ($data, $custom_view) {
            if ($data instanceof CustomValue) {
                $child = $data->getValue('child');
                $child_view = $data->getValue('child_view');
                $child_ajax = $data->getValue('child_ajax');
                return $child?->getValue('odd_even') == 'odd'
                    && $child_view?->getValue('odd_even') == 'even'
                    && $child_ajax?->getValue('odd_even') == 'odd';
            } else {
                $column_item = $custom_view->custom_view_columns[0]->column_item;
                $unique_name = $column_item?->uniqueName();
                $column_item_2 = $custom_view->custom_view_columns[1]->column_item;
                $unique_name_2 = $column_item_2->uniqueName();
                $column_item_3 = $custom_view->custom_view_columns[2]->column_item;
                $unique_name_3 = $column_item_3->uniqueName();
                return array_get($data, $unique_name) == 'odd'
                    && array_get($data, $unique_name_2) == 'even'
                    && array_get($data, $unique_name_3) == 'odd';
            }
        }, $options);
    }

    /**
     * 4-2.
     * CustomViewColumn = select_table : NO
     * CustomViewFilter = select_table : YES
     * (This table has multiple columns that reference the same table)
     */
    public function testFuncFilterSelectSameTableNoColumn()
    {
        $this->init();

        $options = $this->getOptions(
            ['id', 'created_at'],
            ['child_table.odd_even.child', 'child_table.odd_even.child_view', 'child_table.odd_even.child_ajax']
        );

        $options['target_table_name'] = TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE;
        $options['filter_settings'][0]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][0]['filter_value_text'] = 'even';
        $options['filter_settings'][1]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][1]['filter_value_text'] = 'odd';
        $options['filter_settings'][2]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][2]['filter_value_text'] = 'even';

        $array = $this->getColumnFilterData(function ($data, $custom_view) {
            if (!($data instanceof CustomValue)) {
                $id = array_get($data, 'id');
                $data = getModelName(TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE)::find($id);
            }
            $child = $data?->getValue('child');
            $child_view = $data?->getValue('child_view');
            $child_ajax = $data?->getValue('child_ajax');
            return $child?->getValue('odd_even') == 'even'
                && $child_view?->getValue('odd_even') == 'odd'
                && $child_ajax?->getValue('odd_even') == 'even';
        }, $options);
    }

    /**
     * 4-3
     * CustomViewColumn = select_table : YES(id)
     * CustomViewFilter = select_table : YES(id)
     * (This table has multiple columns that reference the same table)
     */
    public function testFuncFilterSelectSameTableId()
    {
        $this->init();

        $options = $this->getOptions(
            ['child_table.id.child', 'child_table.id.child_view', 'child_table.id.child_ajax', 'id', 'parent_table.id.parent'],
            ['child_table.id.child', 'child_table.id.child_view', 'child_table.id.child_ajax']
        );

        $target_data =  getModelName(TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE)::find(5);
        $target_id_1 = $target_data?->getValue('child', ValueType::PURE_VALUE);
        $target_id_2 = $target_data?->getValue('child_view', ValueType::PURE_VALUE);
        $target_id_3 = $target_data?->getValue('child_ajax', ValueType::PURE_VALUE);

        $options['target_table_name'] = TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE;
        $options['filter_settings'][0]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][0]['filter_value_text'] = $target_id_1;
        $options['filter_settings'][1]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][1]['filter_value_text'] = $target_id_2;
        $options['filter_settings'][2]['filter_condition'] = FilterOption::EQ;
        $options['filter_settings'][2]['filter_value_text'] = $target_id_3;

        $array = $this->getColumnFilterData(function ($data, $custom_view) use ($target_id_1, $target_id_2, $target_id_3) {
            if ($data instanceof CustomValue) {
                $child = $data->getValue('child');
                $child_view = $data->getValue('child_view');
                $child_ajax = $data->getValue('child_ajax');
                return array_get($child, 'id') == $target_id_1
                    && array_get($child_view, 'id') == $target_id_2
                    && array_get($child_ajax, 'id') == $target_id_3;
            } else {
                $column_item = $custom_view->custom_view_columns[0]->column_item;
                $unique_name = $column_item?->uniqueName();
                $column_item_2 = $custom_view->custom_view_columns[1]->column_item;
                $unique_name_2 = $column_item_2->uniqueName();
                $column_item_3 = $custom_view->custom_view_columns[2]->column_item;
                $unique_name_3 = $column_item_3->uniqueName();
                return array_get($data, $unique_name) == $target_id_1
                    && array_get($data, $unique_name_2) == $target_id_2
                    && array_get($data, $unique_name_3) == $target_id_3;
            }
        }, $options);
    }

    /**
     * 4-4.
     * CustomViewColumn = select_table : NO
     * CustomViewFilter = select_table : YES(created_user)
     * (This table has multiple columns that reference the same table)
     */
    public function testFuncFilterSelectSameTableIdNoColumn()
    {
        $this->init();

        $options = $this->getOptions(
            ['id', 'created_user'],
            ['child_table.created_user.child', 'child_table.created_user.child_view', 'child_table.created_user.child_ajax']
        );

        $target_data = getModelName(TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE)::find(3);
        $target_id_1 = array_get($target_data?->getValue('child'), 'created_user_id');
        $target_id_2 = array_get($target_data?->getValue('child_view'), 'created_user_id');
        $target_id_3 = array_get($target_data?->getValue('child_ajax'), 'created_user_id');

        $options['target_table_name'] = TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE;
        $options['filter_settings'][0]['filter_condition'] = FilterOption::USER_EQ;
        $options['filter_settings'][0]['filter_value_text'] = $target_id_1;
        $options['filter_settings'][1]['filter_condition'] = FilterOption::USER_EQ;
        $options['filter_settings'][1]['filter_value_text'] = $target_id_2;
        $options['filter_settings'][2]['filter_condition'] = FilterOption::USER_EQ;
        $options['filter_settings'][2]['filter_value_text'] = $target_id_3;

        $array = $this->getColumnFilterData(function ($data, $custom_view) use ($target_id_1, $target_id_2, $target_id_3) {
            if (!($data instanceof CustomValue)) {
                $id = array_get($data, 'id');
                $data = getModelName(TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE)::find($id);
            }
            $child = $data?->getValue('child');
            $child_view = $data?->getValue('child_view');
            $child_ajax = $data?->getValue('child_ajax');
            return array_get($child, 'created_user_id') == $target_id_1
                && array_get($child_view, 'created_user_id') == $target_id_2
                && array_get($child_ajax, 'created_user_id') == $target_id_3;
        }, $options);
    }

    /**
     * 4-5.
     * CustomViewColumn = select_table : YES
     * CustomViewSort = select_table : YES
     * (This table has multiple columns that reference the same table)
     */
    public function testFuncSortBySelectSameTable()
    {
        $this->init();

        $options = $this->getOptions(
            ['child_table.date.child', 'child_table.date.child_view', 'child_table.date.child_ajax', 'id', 'parent_table.integer.parent'],
            [],
            ['child_table.date.child', 'child_table.date.child_view', 'child_table.date.child_ajax']
        );

        $options['target_table_name'] = TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE;
        $options['sort_settings'][0]['sort'] = ViewColumnSort::ASC;
        $options['sort_settings'][0]['priority'] = 1;
        $options['sort_settings'][1]['sort'] = ViewColumnSort::DESC;
        $options['sort_settings'][1]['priority'] = 2;
        $options['sort_settings'][2]['sort'] = ViewColumnSort::ASC;
        $options['sort_settings'][2]['priority'] = 3;

        $array = $this->getColumnFilterData(function ($prev_data, $data, $custom_view) {
            $column_item = $custom_view->custom_view_columns[0]->column_item;
            $unique_name = $column_item?->uniqueName();
            $column_item_2 = $custom_view->custom_view_columns[1]->column_item;
            $unique_name_2 = $column_item_2->uniqueName();
            $column_item_3 = $custom_view->custom_view_columns[2]->column_item;
            $unique_name_3 = $column_item_3->uniqueName();
            if (array_get($prev_data, $unique_name) < array_get($data, $unique_name)) {
                return true;
            }
            if (array_get($prev_data, $unique_name) == array_get($data, $unique_name)
                && array_get($prev_data, $unique_name_2) > array_get($data, $unique_name_2)) {
                return true;
            }
            return array_get($prev_data, $unique_name) == array_get($data, $unique_name)
                && array_get($prev_data, $unique_name_2) == array_get($data, $unique_name_2)
                && array_get($prev_data, $unique_name_3) <= array_get($data, $unique_name_3);
        }, $options);
    }

    /**
     * 4-6.
     * CustomViewColumn = select_table : NO
     * CustomViewSort = select_table : YES
     * (This table has multiple columns that reference the same table)
     */
    public function testFuncSortBySelectSameTableNoColumn()
    {
        $this->init();

        $options = $this->getOptions(
            ['id', 'created_at'],
            [],
            ['child_table.date.child', 'child_table.date.child_view', 'child_table.date.child_ajax']
        );

        $options['target_table_name'] = TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE;
        $options['sort_settings'][0]['sort'] = ViewColumnSort::DESC;
        $options['sort_settings'][0]['priority'] = 1;
        $options['sort_settings'][1]['sort'] = ViewColumnSort::ASC;
        $options['sort_settings'][1]['priority'] = 2;
        $options['sort_settings'][2]['sort'] = ViewColumnSort::ASC;
        $options['sort_settings'][2]['priority'] = 3;

        $pivot_table = getModelName(TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE);

        $array = $this->getColumnFilterData(function ($prev_data, $data, $custom_view) use ($pivot_table) {
            if (!($data instanceof CustomValue)) {
                $data = $pivot_table::find(array_get($data, 'id'));
                $prev_data = $pivot_table::find(array_get($prev_data, 'id'));
            }
            $child = $data?->getValue('child');
            $prev_child = $prev_data?->getValue('child');
            $child_view = $data?->getValue('child_view');
            $prev_child_view = $prev_data?->getValue('child_view');
            $child_ajax = $data?->getValue('child_ajax');
            $prev_child_ajax = $prev_data?->getValue('child_ajax');
            if ($prev_child?->getValue('date') > $child?->getValue('date')) {
                return true;
            }
            if ($prev_child?->getValue('date') < $child?->getValue('date')) {
                return false;
            }
            if ($prev_child_view?->getValue('date') < $child_view?->getValue('date')) {
                return true;
            }
            if ($prev_child_view?->getValue('date') > $child_view?->getValue('date')) {
                return false;
            }
            return $prev_child_ajax?->getValue('date') <= $child_ajax?->getValue('date');
        }, $options);
    }

    /**
     * 4-7.
     * CustomViewColumn = select_table : YES
     * CustomViewSort = select_table : YES(id)
     * (This table has multiple columns that reference the same table)
     */
    public function testFuncSortBySelectSameTableId()
    {
        $this->init();

        $options = $this->getOptions(
            ['child_table.id.child', 'child_table.id.child_view', 'child_table.id.child_ajax', 'id', 'parent_table.id.parent'],
            [],
            ['child_table.id.child', 'child_table.id.child_view', 'child_table.id.child_ajax']
        );

        $options['target_table_name'] = TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE;
        $options['sort_settings'][0]['sort'] = ViewColumnSort::ASC;
        $options['sort_settings'][0]['priority'] = 1;
        $options['sort_settings'][1]['sort'] = ViewColumnSort::DESC;
        $options['sort_settings'][1]['priority'] = 2;
        $options['sort_settings'][2]['sort'] = ViewColumnSort::ASC;
        $options['sort_settings'][2]['priority'] = 3;

        $array = $this->getColumnFilterData(function ($prev_data, $data, $custom_view) {
            $column_item = $custom_view->custom_view_columns[0]->column_item;
            $unique_name = $column_item?->uniqueName();
            $column_item_2 = $custom_view->custom_view_columns[1]->column_item;
            $unique_name_2 = $column_item_2?->uniqueName();
            $column_item_3 = $custom_view->custom_view_columns[2]->column_item;
            $unique_name_3 = $column_item_3?->uniqueName();
            if (array_get($prev_data, $unique_name) < array_get($data, $unique_name)) {
                return true;
            }
            if (array_get($prev_data, $unique_name) == array_get($data, $unique_name)
                && array_get($prev_data, $unique_name_2) > array_get($data, $unique_name_2)) {
                return true;
            }
            return array_get($prev_data, $unique_name) == array_get($data, $unique_name)
                && array_get($prev_data, $unique_name_2) == array_get($data, $unique_name_2)
                && array_get($prev_data, $unique_name_3) <= array_get($data, $unique_name_3);
        }, $options);
    }

    /**
     * 4-8.
     * CustomViewColumn = select_table : NO
     * CustomViewSort = select_table : YES(created_user)
     * (This table has multiple columns that reference the same table)
     */
    public function testFuncSortBySelectSameTableIdNoColumn()
    {
        $this->init();

        $options = $this->getOptions(
            ['id', 'created_user'],
            [],
            ['child_table.created_user.child', 'child_table.created_user.child_view', 'child_table.created_user.child_ajax']
        );

        $options['target_table_name'] = TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE;
        $options['sort_settings'][0]['sort'] = ViewColumnSort::DESC;
        $options['sort_settings'][0]['priority'] = 1;
        $options['sort_settings'][1]['sort'] = ViewColumnSort::DESC;
        $options['sort_settings'][1]['priority'] = 2;
        $options['sort_settings'][2]['sort'] = ViewColumnSort::ASC;
        $options['sort_settings'][2]['priority'] = 3;

        $pivot_table = getModelName(TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE);

        $array = $this->getColumnFilterData(function ($prev_data, $data, $custom_view) use ($pivot_table) {
            if (!($data instanceof CustomValue)) {
                $data = $pivot_table::find(array_get($data, 'id'));
                $prev_data = $pivot_table::find(array_get($prev_data, 'id'));
            }
            $child = $data?->getValue('child');
            $prev_child = $prev_data?->getValue('child');
            $child_view = $data?->getValue('child_view');
            $prev_child_view = $prev_data?->getValue('child_view');
            $child_ajax = $data?->getValue('child_ajax');
            $prev_child_ajax = $prev_data?->getValue('child_ajax');
            if (array_get($prev_child, 'created_user_id') > array_get($child, 'created_user_id')) {
                return true;
            }
            if (array_get($prev_child, 'created_user_id') == array_get($child, 'created_user_id')
                && array_get($prev_child_view, 'created_user_id') > array_get($child_view, 'created_user_id')) {
                return true;
            }
            return array_get($prev_child, 'created_user_id') == array_get($child, 'created_user_id')
                && array_get($prev_child_view, 'created_user_id') == array_get($child_view, 'created_user_id')
                && array_get($prev_child_ajax, 'created_user_id') <= array_get($child_ajax, 'created_user_id');
        }, $options);
    }

    protected function getOptions(array $columns, array $filters = [], array $sorts = [])
    {
        $options = [
            'target_table_name' => TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE,
            'column_settings' => [],
            'filter_settings' => [],
            'sort_settings' => [],
        ];

        foreach ($columns as $column) {
            $options['column_settings'][] = $this->getColumnSetting($column);
        }

        foreach ($filters as $filter) {
            $options['filter_settings'][] = $this->getColumnSetting($filter);
        }

        foreach ($sorts as $sort) {
            $options['sort_settings'][] = $this->getColumnSetting($sort);
        }

        return $options;
    }

    protected function getColumnSetting(string $column)
    {
        $parts = \explode('.', $column);
        if (count($parts) > 1) {
            $refer_table = $parts[0];
            $column = $parts[1];
            if (count($parts) > 2) {
                $refer_column = $parts[2];
            }
        }

        $column_setting = [
            'column_name' => $column,
        ];

        $system_info = SystemColumn::getOption(['name' => $column]);
        if (isset($system_info)) {
            $column_setting['condition_type'] = ConditionType::SYSTEM;
        }

        if (isset($refer_table)) {
            $column_setting['reference_table'] = $refer_table;
            if (isset($refer_column)) {
                $column_setting['reference_column'] = $refer_column;
            } else {
                $column_setting['reference_column'] = SystemColumn::PARENT_ID;
            }
        }

        return $column_setting;
    }


    protected function getColumnFilterData(\Closure $testCallback, array $options = [])
    {
        // create custom view
        list($custom_table, $custom_view) = $this->createCustomViewAll($options);

        $classname = getModelName($custom_table->table_name);
        $grid = new Grid(new $classname());
        $grid->paginate(100);

        $custom_view->filterSortModel($grid->model());
        $custom_view->setGrid($grid);

        $grid->build();
        $data = $grid->rows();

        $this->__testFilter($data, $custom_view, $testCallback, $options);
    }

    protected function __testFilter(\Illuminate\Support\Collection $collection, $custom_view, \Closure $testCallback, array $options = [])
    {
        $options = array_merge(
            [
                'target_table_name' => TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST,
            ],
            $options
        );

        // check if view column exists
        if ($collection->count() > 0) {
            foreach ($custom_view->custom_view_columns as $custom_view_column) {
                $column_item = $custom_view_column->column_item;
                $unique_name = $column_item?->uniqueName();
                $matchResult = array_key_exists($unique_name, $collection[0]->model());
                $this->assertTrue($matchResult, 'matchResult is false. Target column is notfound.' . $custom_view_column->id);
            }
        }

        // check if filter is correct
        if (!empty($options['filter_settings'])) {
            // get filter matched count.
            foreach ($collection as $data) {
                $matchResult = $testCallback($data?->model(), $custom_view);

                $this->assertTrue($matchResult, 'matchResult is false. Target id is ' . $data?->id);
            }

            // check not getted values.
            $custom_table = CustomTable::getEloquent($options['target_table_name']);
            $ids = $collection->map(function ($data) {
                return array_get($data?->model(), 'id');
            })->toArray();
            $notMatchedValues = $custom_table->getValueQuery()->whereNotIn('id', $ids)->get();

            /** @var CustomTable|null $data */
            foreach ($notMatchedValues as $data) {
                $matchResult = $testCallback($data, $custom_view);

                $this->assertTrue(!$matchResult, 'Expect matchResult is false, but matched. Target id is ' . $data?->id);
            }
        }

        // check if sort is correct
        if (!empty($options['sort_settings'])) {
            $prev_data = null;
            foreach ($collection as $data) {
                if (isset($prev_data)) {
                    $matchResult = $testCallback($prev_data, $data?->model(), $custom_view);
                    $this->assertTrue($matchResult, 'matchResult is false. Sort order is wrong. ' . array_get($data?->model(), 'id'));
                }

                $prev_data = $data?->model();
            }
        }
    }
}
