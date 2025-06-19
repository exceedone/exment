<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\RelationTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Linkage;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Services\FormSetting\FormColumn\ColumnBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Services\AuthUserOrgHelper;
use Illuminate\Support\Collection;

class SelectTableTest extends UnitTestBase
{
    // Default Select Table ----------------------------------------------------
    /**
     * @return void
     */
    public function testSelectTableUser()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, []);
    }

    /**
     * @return void
     */
    public function testSelectTableAdmin()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['login_user_admin' => true]);
    }

    /**
     * @return void
     */
    public function testSelectTableAjaxUser()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['select_load_ajax' => true]);
    }

    /**
     * @return void
     */
    public function testSelectTableAjaxAdmin()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['login_user_admin' => true, 'select_load_ajax' => true]);
    }

    /**
     * @return void
     */
    public function testSelectTableViewUser()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['target_view' => true]);
    }

    /**
     * @return void
     */
    public function testSelectTableViewAdmin()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['login_user_admin' => true, 'target_view' => true]);
    }

    /**
     * @return void
     */
    public function testSelectTableViewAjaxUser()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['target_view' => true, 'select_load_ajax' => true]);
    }

    /**
     * @return void
     */
    public function testSelectTableViewAjaxAdmin()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['login_user_admin' => true, 'select_load_ajax' => true, 'target_view' => true]);
    }


    // relation filter ----------------------------------------------------
    // One : Many ----------------------------------------------------
    /**
     * @return void
     */
    public function testSelectTableRelationOneMany()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['relation_filter' => SearchType::ONE_TO_MANY]);
    }

    /**
     * @return void
     */
    public function testSelectTableRelationOneManyAjax()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['relation_filter' => SearchType::ONE_TO_MANY, 'select_load_ajax' => true]);
    }

    /**
     * @return void
     */
    public function testSelectTableRelationOneManyView()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['relation_filter' => SearchType::ONE_TO_MANY, 'target_view' => true]);
    }


    /**
     * @return void
     */
    public function testSelectTableRelationOneManyViewAjax()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['relation_filter' => SearchType::ONE_TO_MANY, 'target_view' => true, 'select_load_ajax' => true]);
    }


    // Many : Many ----------------------------------------------------
    /**
     * @return void
     */
    public function testSelectTableRelationManyMany()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['relation_filter' => SearchType::MANY_TO_MANY]);
    }

    /**
     * @return void
     */
    public function testSelectTableRelationManyManyAjax()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['relation_filter' => SearchType::MANY_TO_MANY, 'select_load_ajax' => true]);
    }

    /**
     * @return void
     */
    public function testSelectTableRelationManyManyView()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['relation_filter' => SearchType::MANY_TO_MANY, 'target_view' => true]);
    }

    /**
     * @return void
     */
    public function testSelectTableRelationManyManyViewAjax()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['relation_filter' => SearchType::MANY_TO_MANY, 'target_view' => true, 'select_load_ajax' => true]);
    }



    // Select Table ----------------------------------------------------
    /**
     * @return void
     */
    public function testSelectTableRelationSelectTable()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['relation_filter' => SearchType::SELECT_TABLE]);
    }


    /**
     * @return void
     */
    public function testSelectTableRelationSelectTableAjax()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['relation_filter' => SearchType::SELECT_TABLE, 'select_load_ajax' => true]);
    }


    /**
     * @return void
     */
    public function testSelectTableRelationSelectTableView()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['relation_filter' => SearchType::SELECT_TABLE, 'target_view' => true]);
    }

    /**
     * @return void
     */
    public function testSelectTableRelationSelectTableViewAjax()
    {
        $this->executeSelectTableTest(ColumnType::SELECT_TABLE, ['relation_filter' => SearchType::SELECT_TABLE, 'target_view' => true, 'select_load_ajax' => true]);
    }



    // Default User ----------------------------------------------------
    /**
     * @return void
     */
    public function testUserUser()
    {
        $this->executeSelectTableTest(ColumnType::USER, []);
    }

    /**
     * @return void
     */
    public function testUserAdmin()
    {
        $this->executeSelectTableTest(ColumnType::USER, ['login_user_admin' => true]);
    }


    /**
     * @return void
     */
    public function testUserAjaxUser()
    {
        $this->executeSelectTableTest(ColumnType::USER, ['select_load_ajax' => true]);
    }

    /**
     * @return void
     */
    public function testUserAjaxAdmin()
    {
        $this->executeSelectTableTest(ColumnType::USER, ['login_user_admin' => true, 'select_load_ajax' => true]);
    }


    /**
     * @return void
     */
    public function testUserViewUser()
    {
        $this->executeSelectTableTest(ColumnType::USER, ['target_view' => true]);
    }

    /**
     * @return void
     */
    public function testUserViewAdmin()
    {
        $this->executeSelectTableTest(ColumnType::USER, ['login_user_admin' => true, 'target_view' => true]);
    }


    /**
     * @return void
     */
    public function testUserViewAjaxUser()
    {
        $this->executeSelectTableTest(ColumnType::USER, ['target_view' => true, 'select_load_ajax' => true]);
    }

    /**
     * @return void
     */
    public function testUserViewAjaxAdmin()
    {
        $this->executeSelectTableTest(ColumnType::USER, ['login_user_admin' => true, 'select_load_ajax' => true, 'target_view' => true]);
    }


    // Relation filter ----------------------------------------------------
    /**
     * @return void
     */
    public function testUserRelation()
    {
        $this->executeSelectTableTest(ColumnType::USER, ['relation_filter' => SearchType::MANY_TO_MANY]);
    }


    /**
     * @return void
     */
    public function testUserRelationAjax()
    {
        $this->executeSelectTableTest(ColumnType::USER, ['relation_filter' => SearchType::MANY_TO_MANY, 'select_load_ajax' => true]);
    }


    /**
     * @return void
     */
    public function testUserRelationView()
    {
        $this->executeSelectTableTest(ColumnType::USER, ['relation_filter' => SearchType::MANY_TO_MANY, 'target_view' => true]);
    }


    /**
     * @return void
     */
    public function testUserRelationViewAjax()
    {
        $this->executeSelectTableTest(ColumnType::USER, ['relation_filter' => SearchType::MANY_TO_MANY, 'target_view' => true, 'select_load_ajax' => true]);
    }





    // common ----------------------------------------------------

    /**
     * Execute select table test.
     * (1)Get options using select table's method.
     * (2)Get ids searching custom value directly.
     * Check matching (1) and (2)
     *
     * @param string $column_type
     * @param array<mixed> $options
     * @return void
     */
    protected function executeSelectTableTest($column_type, array $options)
    {
        $options = array_merge(
            [
                'login_user_admin' => false, // if true, login as admin. else normal user. if normal user, options has only items user has permission.
                'relation_filter' => null, // if set relation_filter, execute relation filter.
                'target_view' => false, // if set target view, filter view
                'select_load_ajax' => false, // if true, select_load_ajax
            ],
            $options
        );

        $this->initAllTest();

        // Login user.
        $this->be(LoginUser::find($options['login_user_admin'] ? TestDefine::TESTDATA_USER_LOGINID_ADMIN : TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC));

        // get target column.
        $custom_column = $this->getTargetColumn($column_type, $options);
        if (is_nullorempty($custom_column)) {
            $this->assertTrue(false, 'target column is not found. Please check.');
            return;
        }

        $custom_table = $custom_column->custom_table_cache;

        // get column item
        $custom_item = $custom_column->column_item;

        $field = $custom_item->getAdminField();
        list($parentValue, $linkage) = $this->getParentValueAndLinkage($custom_column, $options);
        $fieldOptions = $this->getSelectFieldOptions($custom_column, $linkage, $parentValue, $options);

        // get select option(Displaying)
        $select_options = collect($custom_item->getSelectOptions(null, $field, $fieldOptions))->keys()->unique();
        // get ids directly
        $ids = $this->searchCustomValueDirectly($custom_column, $parentValue, $options)->unique();

        $this->assertTrue($this->isMatchIds($select_options, $ids), "Select options is {$select_options->implode(',')}, but ids is {$ids->implode(',')}.");
    }

    /**
     * Get test target column.
     * @param ColumnType|string $column_type
     * @param array<mixed> $options
     * @return CustomColumn
     */
    protected function getTargetColumn($column_type, array $options)
    {
        if ($column_type == ColumnType::USER) {
            if (isset($options['relation_filter'])) {
                return $this->getTargetColumnTable(TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE_USER_ORG, TestDefine::TESTDATA_COLUMN_NAMES['user_relation_filter'], $options);
            }

            return $this->getTargetColumnTable(TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE_USER_ORG, TestDefine::TESTDATA_COLUMN_NAMES['user'], $options);
        }

        if ($column_type == ColumnType::ORGANIZATION) {
            return $this->getTargetColumnTable(TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE_USER_ORG, TestDefine::TESTDATA_COLUMN_NAMES['organization'], $options);
        }

        if (isset($options['relation_filter'])) {
            $table_name = null;
            switch ($options['relation_filter']) {
                case SearchType::ONE_TO_MANY:
                    $table_name = TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE;
                    break;
                case SearchType::MANY_TO_MANY:
                    $table_name = TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE_MANY_TO_MANY;
                    break;
                case SearchType::SELECT_TABLE:
                    $table_name = TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE_SELECT;
                    break;
            }

            return $this->getTargetColumnTable($table_name, TestDefine::TESTDATA_COLUMN_NAMES['relation_filter'], $options);
        }

        return $this->getTargetColumnTable(TestDefine::TESTDATA_TABLE_NAME_PIVOT_TABLE, TestDefine::TESTDATA_COLUMN_NAMES['default'], $options);
    }

    /**
     * @param string $custom_table_name
     * @param array<string, mixed> $columns
     * @param array<string, mixed> $options
     * @return CustomColumn|null
     */
    protected function getTargetColumnTable(string $custom_table_name, array $columns, array $options)
    {
        if (boolval($options['select_load_ajax'])) {
            if (boolval($options['target_view'])) {
                return CustomColumn::getEloquent($columns['ajax_view'], $custom_table_name);
            }
            return CustomColumn::getEloquent($columns['ajax'], $custom_table_name);
        }

        if (boolval($options['target_view'])) {
            return CustomColumn::getEloquent($columns['view'], $custom_table_name);
        }
        return CustomColumn::getEloquent($columns['default'], $custom_table_name);
    }

    /**
     * Get select field options.
     *
     * @param CustomColumn $custom_column
     * @param Linkage|null $linkage
     * @param string|null $parentValue
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    protected function getSelectFieldOptions($custom_column, $linkage, $parentValue, array $options)
    {
        if (!isset($options['relation_filter'])) {
            return [];
        }
        $column_item = $custom_column->column_item;

        // get callback
        $callback = function (&$query) use ($linkage, $parentValue) {
            if (!$linkage) {
                return;
            }

            $linkage->setQueryFilter($query, $parentValue);
        };
        $selectOption = $this->callProtectedMethod($column_item, 'getSelectFieldOptions', $callback);

        return $selectOption;
    }


    /**
     * Get parent value and linakge for relation filter
     *
     * @param CustomColumn $custom_column
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    protected function getParentValueAndLinkage($custom_column, array &$options)
    {
        if (!isset($options['relation_filter'])) {
            return [null, null];
        }

        // get form_column
        $custom_form_column = CustomFormColumn::where('form_column_target_id', $custom_column->id)->where('form_column_type', FormColumnType::COLUMN)->orderBy('id', 'desc')->first();
        $custom_column->column_item->setFormColumnOptions($custom_form_column);
        // copy and paste from SelectTable.php
        $linkage = $this->callProtectedMethod($custom_column->column_item, 'getLinkage', ['relation_filter_target_column_id' => $custom_form_column ? $custom_form_column->id : null]);
        if (!isset($linkage)) {
            return [null, null];
        }

        // set option parent_table and child table
        $options['parent_table'] = $linkage->parent_column->select_target_table;
        $options['child_table'] = $linkage->child_column->select_target_table;

        $select_target_table = $linkage->parent_column->select_target_table;
        if (!isset($select_target_table)) {
            return [null, $linkage];
        }

        return [$select_target_table->getValueModel()->first()->id, $linkage];
    }


    /**
     * search custom value directly
     * @param mixed $custom_column
     * @param mixed $value
     * @param array<string, mixed> $options
     * @return Collection<int|string, mixed>
     */
    protected function searchCustomValueDirectly($custom_column, $value, array $options)
    {
        // if select ajax is true, return empty ids,
        if (boolval($options['select_load_ajax'])) {
            return collect([]);
        }

        $target_table = $custom_column->select_target_table;

        $query = $target_table->getValueQuery();

        //TODO:filtering options

        // user permission does not need filter.

        // target_view
        if (boolval($options['target_view'])) {
            $target_view = $custom_column->select_target_view;
            if (isset($target_view)) {
                $target_view->filterSortModel($query);
            }
        }

        // if column_type is or user, filter
        if ($custom_column->column_type == ColumnType::USER) {
            AuthUserOrgHelper::getRoleUserAndOrgBelongsUserQueryTable($custom_column->custom_table_cache, null, $query);
        } elseif ($custom_column->column_type == ColumnType::ORGANIZATION) {
            AuthUserOrgHelper::getRoleOrganizationQueryTable($custom_column->custom_table_cache, null, $query);
        }


        // relation filter
        switch ($options['relation_filter']) {
            case SearchType::ONE_TO_MANY:
                RelationTable::setQueryOneMany($query, array_get($options, 'parent_table'), array_get($options, 'child_table'), $value);
                break;
            case SearchType::MANY_TO_MANY:
                RelationTable::setQueryManyMany($query, array_get($options, 'parent_table'), array_get($options, 'child_table'), $value);
                break;
            case SearchType::SELECT_TABLE:
                $search_column = $target_table->getSelectTableColumns(array_get($options, 'parent_table'))->first();
                RelationTable::setQuerySelectTable($query, $search_column, $value);
                break;
        }

        $ids = $query->pluck('id');

        return $ids;
    }

    /**
     * Is match collect1 and 2
     *
     * @param Collection<int, (int|string)>  $correct1
     * @param Collection<int|string, mixed> $correct2
     * @return boolean
     */
    protected function isMatchIds($correct1, $correct2)
    {
        return $correct1->diff($correct2)->count() === 0 && $correct2->diff($correct1)->count() === 0;
    }
}
