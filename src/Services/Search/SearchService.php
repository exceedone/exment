<?php

namespace Exceedone\Exment\Services\Search;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\RelationTable;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewGridFilter;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\SystemColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Custom Value's Search model.
 * "where" query support CustomColumn, CustomViewFilter, Condition.
 * "orderBy" query support CustomColumn, CustomViewSort, Condition.
 */
class SearchService
{
    /**
     * Target CustomTable
     *
     * @var CustomTable
     */
    protected $custom_table;

    /**
     * Custom Value's query.
     *
     * @var \Illuminate\Database\Eloquent\Builder
     */
    protected $query;

    /**
     * Whether is append select for target custom_table's columns.
     *
     * @var bool
     */
    protected $isAppendSelect = true;

    /**
     * Whether is already append select for target custom_table's columns.
     *
     * @var bool
     */
    protected $alreadyAppendSelect = false;

    /**
     * Already joined tables
     *
     * @var array
     */
    protected $joinedTables = [];

    /**
     * Already joined workflows(status, work_user)
     *
     * @var array
     */
    protected $joinedWorkflows = [];

    /**
     * Summary orders
     * @var array
     */
    protected $summaryOrders = [];

    /**
     * Summary Joins.
     * "joinSub" query only calls once, so First set select and group by, and after these, join sub query.
     * @var array
     */
    protected $summaryJoins = [];


    public function __construct(CustomTable $custom_table)
    {
        $this->custom_table = $custom_table;
        $this->query = $custom_table->getValueQuery();
    }

    /**
     * Get eloquent query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function query()
    {
        if ($this->isAppendSelect) {
            $this->addSelect();
        }
        return $this->query;
    }


    /**
     * Add select query
     *
     * @return $this
     */
    public function addSelect()
    {
        if (!$this->alreadyAppendSelect) {
            $db_table_name = getDBTableName($this->custom_table);
            $this->query->select("$db_table_name.*");

            $this->alreadyAppendSelect = true;
        }
        return $this;
    }

    /**
     * Set eloquent query
     *
     * @return $this
     */
    public function setQuery($query)
    {
        $this->query = $query;
        return $this;
    }

    /**
     * Whether is append select for target custom_table's columns.
     *
     * @param boolean $isAppendSelect
     * @return $this
     */
    public function setIsAppendSelect(bool $isAppendSelect)
    {
        $this->isAppendSelect = $isAppendSelect;
        return $this;
    }

    /**
     * Get query's value.
     *
     * @param  array|string  $columns
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function get($columns = ['*'])
    {
        return $this->query()->get($columns);
    }


    /**
     * Add a where column.
     * If CustomColumn, and linkage(relation or select table), add where exists query.
     *
     * @param  CustomColumn|string|\Closure|array|\Illuminate\Database\Query\Expression $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        [$value, $operator] = $this->query->getQuery()->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2
        );

        // If custom column, execute where custom column's exists query.
        if ($column instanceof CustomColumn) {
            return $this->whereCustomColumn($column, $operator, $value, $boolean);
        }
        if (is_string($column)) {
            return $this->whereCustomColumn(CustomColumn::getEloquent($column, $this->custom_table), $operator, $value, $boolean);
        }

        $this->query->where($column, $operator, $value, $boolean);
        return $this;
    }


    /**
     * Add an "order by" clause to the query.
     * If CustomColumn, and linkage(relation or select table), add where exists query.
     *
     * @param  \Closure|CustomColumn|\Illuminate\Database\Query\Builder|\Illuminate\Database\Query\Expression|string  $column
     * @param  string  $direction
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function orderBy($column, $direction = 'asc')
    {
        // If custom column, execute where custom column's exists query.
        if ($column instanceof CustomColumn) {
            return $this->orderByCustomColumn($column, $direction);
        }
        if (is_string($column)) {
            return $this->orderByCustomColumn(CustomColumn::getEloquent($column, $this->custom_table), $direction);
        }

        $this->query->orderBy($column, $direction);
        return $this;
    }



    /**
     * Add a where custom column. Contains CustomColumn.
     * and linkage(relation or select table), add where exists query.
     *
     * @param  CustomColumn $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this|Builder
     */
    protected function whereCustomColumn(CustomColumn $column, $operator = null, $value = null, $boolean = 'and')
    {
        $whereCustomTable = $column->custom_table_cache;
        $column_item = $column->column_item;

        if (!isMatchString($whereCustomTable->id, $this->custom_table->id)) {
            // get RelationTable info.
            $relationTable = $this->getRelationTable($whereCustomTable);

            if (!$relationTable) {
                return $this->query->whereNotMatch();
            }
            // set relation query using relation type class.
            else {
                $this->setJoin($relationTable, $whereCustomTable);

                $column_item->setUniqueTableName($relationTable->tableUniqueName);
            }
        }

        $column_name = $column_item->getTableColumn();

        // Add where query
        $this->query->where($column_name, $operator, $value, $boolean);

        return $this;
    }


    /**
     * Add orderby custom column. Contains CustomColumn or string.
     * and linkage(relation or select table), add where exists query.
     *
     * @param  CustomColumn $column
     * @param  string  $direction
     * @return $this
     */
    protected function orderByCustomColumn(CustomColumn $column, $direction = 'asc')
    {
        $whereCustomTable = $column->custom_table_cache;
        if (isMatchString($whereCustomTable->id, $this->custom_table->id)) {
            $this->query->orderBy($column->getQueryKey(), $direction);
            return $this;
        }

        // get RelationTable info.
        $relationTable = $this->getRelationTable($whereCustomTable);

        if (!$relationTable) {
            $this->query->whereNotMatch();
        } elseif ((int)$relationTable->searchType === SearchType::MANY_TO_MANY) {
            throw new \Exception('Many to many relation not support order by.');
        }
        // set relation query using relation type class.
        else {
            $this->setJoin($relationTable, $whereCustomTable);

            // Add orderBy query
            $this->query->orderBy($column->getQueryKey(), $direction);
        }

        return $this;
    }


    /**
     * Add orderby custom column. Contains CustomViewSort.
     * and linkage(relation or select table), add where exists query.
     *
     * @param  CustomViewSort $column
     * @return $this
     */
    public function orderByCustomViewSort(CustomViewSort $column)
    {
        // set relation table join.
        $this->setRelationJoin($column, [
            'asOrderBy' => true,
        ]);

        // set sort info.
        $condition_item = $column->condition_item;
        if ($condition_item) {
            $condition_item->setQuerySort($this->query, $column);
        }

        return $this;
    }

    /**
     * Add orderby custom column. Contains CustomViewColumn.
     * Convert to custom view sort directly.
     * and linkage(relation or select table), add where exists query.
     *
     * @param  CustomViewColumn|CustomViewSummary $column
     * @param  string $type 'asc' : 'desc'
     * @return $this
     */
    public function orderByCustomViewColumn($column, $type)
    {
        $custom_view_sort = new CustomViewSort([
            'custom_view_id' => $column->custom_view_id,
            'view_column_type' => $column->view_column_type,
            'view_column_table_id' => $column->view_column_table_id,
            'view_column_target_id' => $column->view_column_target_id,
            'sort' => $type == 'desc' ? -1 : 1,
            'priority' => 1,
            'options' => [
                'view_pivot_table_id' => array_get($column, 'options.view_pivot_table_id'),
                'view_pivot_column_id' => array_get($column, 'options.view_pivot_column_id'),
            ],
        ]);

        return $this->orderByCustomViewSort($custom_view_sort);
    }



    /**
     * Add groupby by custom view column. Contains CustomViewColumn.
     *
     * @param  CustomViewColumn $column
     * @return $this
     */
    public function groupByCustomViewColumn(CustomViewColumn $column)
    {
        // set relation table join.
        $relationTable = $this->setRelationJoin($column, [
            'asSummary' => true,
        ]);

        $column_item = $column->column_item;

        // get group's column. this is wraped.
        $sqlAsName = \Exment::wrapColumn($column_item->sqlAsName());

        // if has sub query(for child relation), set to sub query
        $isSubQuery = false;
        if ($relationTable && SearchType::isSummarySearchType($relationTable->searchType)) {
            $isSubQuery = true;
            $relationTable->subQueryCallbacks[] = function ($subquery, $relationTable) use ($column_item, $sqlAsName) {
                $wrap_column = $column_item->getGroupByWrapTableColumn(true);
                $subquery->selectRaw("$wrap_column AS $sqlAsName");
                $wrap_column = $column_item->getGroupByWrapTableColumn();
                $subquery->groupByRaw($wrap_column);
            };
        }

        // set group by. Maybe if has subquery, set again.
        $wrap_column = $column_item->getGroupByWrapTableColumn(false, $isSubQuery);
        $this->query->groupByRaw($wrap_column);

        // get group's column for select. this is wraped.
        $wrap_column = $column_item->getGroupByWrapTableColumn(true, $isSubQuery);
        // set select column. And add "as".
        $this->query->selectRaw("$wrap_column AS $sqlAsName");

        // if has sort order, set order by
        $this->setSummaryOrderBy($column, $wrap_column);

        return $this;
    }


    /**
     * Add select by custom view summary. Contains CustomViewSummary.
     *
     * @param  CustomViewSummary $column
     * @return $this
     */
    public function selectSummaryCustomViewSummary(CustomViewSummary $column)
    {
        // set relation table join.
        $relationTable = $this->setRelationJoin($column, [
            'asSummary' => true,
        ]);

        $column_item = $column->column_item;

        ///// set summary.
        // $select is wraped.
        $wrap_column = $column_item->getSummaryWrapTableColumn();
        $sqlAsName = \Exment::wrapColumn($column_item->sqlAsName());

        // if has sub query(for child relation), set to sub query
        if ($relationTable && SearchType::isSummarySearchType($relationTable->searchType)) {
            $relationTable->subQueryCallbacks[] = function ($subquery, $relationTable) use ($wrap_column, $sqlAsName) {
                $subquery->selectRaw("$wrap_column AS $sqlAsName");
            };

            // set to default query.
            // MIN, MAX : non summary.
            // COUNT, SUM : SUM.
            $result_column = $column_item->getSummaryJoinResultWrapTableColumn();
            $this->query->selectRaw("$result_column AS $sqlAsName");

            // set to default query group by.
            // Need MIN, MAX.
            // $result_column = $column_item->getGroupByJoinResultWrapTableColumn();
            // if (!is_nullorempty($result_column)) {
            //     $this->query->groupByRaw($result_column);
            // }
        }
        // default, set to default query.
        else {
            $this->query->selectRaw("$wrap_column AS $sqlAsName");
        }

        // if has sort order, set order by
        $this->setSummaryOrderBy($column, $wrap_column);

        return $this;
    }


    /**
     * Set summary order by if has option "sort_order"
     *
     * @return $this
     */
    protected function setSummaryOrderBy($column, $wrap_column)
    {
        $sort_order = array_get($column->options, 'sort_order');
        if (is_nullorempty($sort_order)) {
            return $this;
        }

        $sort_type = isMatchString(array_get($column->options, 'sort_type'), '-1') ? 'desc' : 'asc';

        // set summaryOrders
        $this->summaryOrders[] = [
            'sort_order' => $sort_order,
            'sort_type' => $sort_type,
            'wrap_column' => $wrap_column,
        ];

        return $this;
    }


    /**
     * Execute  order by if for summary
     */
    public function executeSummaryOrderBy()
    {
        foreach (collect($this->summaryOrders)->sortBy('sort_order') as $summaryOrder) {
            //$wrap_column is wraped
            $this->query->orderByRaw("{$summaryOrder['wrap_column']} {$summaryOrder['sort_type']}");
        }
    }

    /**
     * Execute summary join.
     */
    public function executeSummaryJoin()
    {
        foreach ($this->summaryJoins as $summaryJoin) {
            // call summary join.
            $summaryJoin();
        }
    }

    /**
     * Add where by custom view filter.
     *
     * @param  CustomViewFilter $column
     * @return $this
     */
    public function whereCustomViewFilter(CustomViewFilter $column, $filter_is_or, $query = null)
    {
        // if $query is null, set $query as base $this->query.
        // if $query is not null, $query(Setting filter target query) maybe inner where kakko.
        if (is_null($query)) {
            $query = $this->query;
        }

        // // set relation table join.
        // Cannot join in $query->where(function($query), so please call outer $query->where.
        // $this->setRelationJoin($column);

        // set filter info.
        $column->setValueFilter($query, $filter_is_or);

        return $this;
    }

    /**
     * Add where by Notify Schedule.
     * *We want to refactor, but now *
     *
     * @param  Notify $notify notify target
     * @return $this
     */
    public function whereNotifySchedule(Notify $notify, $operator = null, $value = null, $boolean = 'and', array $options = [])
    {
        // if $query is null, set $query as base $this->query.
        // if $query is not null, $query(Setting filter target query) maybe inner where kakko.
        $query = $this->query;

        // set relation table join.
        $this->setRelationJoin($notify);

        // get target table and column
        $table = $this->custom_table;
        $column_item = $this->getColumnItem($notify);

        // If set date format, get date format column.
        if (isset($options['format'])) {
            $column_name = \DB::raw($column_item->getDateFormatWrapTableColumn($options['format']));
        } else {
            $column_name = $column_item->getTableColumn();
        }

        $this->query->where($column_name, $operator, $value, $boolean);

        return $this;
    }

    /**
     * Join relation table for filter or sort
     *
     * @param $column
     * @param array $options
     * @return RelationTable|null
     * @throws \Exception
     */
    public function setRelationJoin($column, array $options = []): ?RelationTable
    {
        $options = array_merge([
            'asSummary' => false,
            'asOrderBy' => false,
        ], $options);
        $asSummary = $options['asSummary'];
        $asOrderBy = $options['asOrderBy'];

        $relationTable = null;

        // get condition params
        list($order_table_id, $order_column_id, $this_table_id, $this_column_id) = $this->getConditionParams($column);
        $orderCustomTable = CustomTable::getEloquent($order_table_id);

        // if not match this table and order table, setJoin relation table.
        if (!isMatchString($order_table_id, $this->custom_table->id)) {
            // get RelationTable info.
            $relationTable = $this->getRelationTable($orderCustomTable, $asSummary, $column);

            if (!$relationTable) {
                $this->query->whereNotMatch();
            } elseif ($asOrderBy && (int)$relationTable->searchType === SearchType::MANY_TO_MANY) {
                throw new \Exception('Many to many relation not support order by.');
            }
            // set relation query using relation type class.
            else {
                $this->setJoin($relationTable, $orderCustomTable);

                // set database unique name
                $column_item = $this->getColumnItem($column);
                if (!isset($column_item)) {
                    $this->query->whereNotMatch();
                    // @phpstan-ignore-next-line Maybe function type hinting miss
                    return $this;
                }
                $column_item->setUniqueTableName($relationTable->tableUniqueName);
            }
        }

        // set relation workflow status
        if ($this->isJoinWorkflowStatus($column)) {
            RelationTable::setWorkflowStatusSubquery($this->query, $this->custom_table, $column->custom_view_cache->filter_is_or);
        }
        // set relation workflow work user
        if ($this->isJoinWorkflowWorkUsers($column)) {
            RelationTable::setWorkflowWorkUsersSubQuery($this->query, $this->custom_table, $column->custom_view_cache->filter_is_or);
        }

        return $relationTable;
    }


    /**
     * Join relation join for workflow. For use workflow view filter.
     *
     * @param string $key
     */
    public function setRelationJoinWorkflow(string $key, array $options = [])
    {
        // set relation workflow status
        if ($this->isJoinWorkflowStatus($key == SystemColumn::WORKFLOW_STATUS)) {
            RelationTable::setWorkflowStatusSubquery($this->query, $this->custom_table, false);
        }
        // set relation workflow work user
        if ($this->isJoinWorkflowWorkUsers($key == SystemColumn::WORKFLOW_WORK_USERS)) {
            RelationTable::setWorkflowWorkUsersSubQuery($this->query, $this->custom_table, false);
        }
    }


    /**
     * Get relation table info
     *
     * @param CustomTable $whereCustomTable
     * @return RelationTable|null relation table info
     */
    protected function getRelationTable($whereCustomTable, bool $asSummary = false, $filterObj = null)
    {
        // get RelationTable info.
        $relationTables = RelationTable::getRelationTables($whereCustomTable, false, [
            'search_enabled_only' => false,
            'get_parent_relation_tables' => $asSummary,
        ])->filter(function ($relationTable) {
            return isMatchString($relationTable->table->id, $this->custom_table->id);
        });

        // filter RelationTable, using custom view filter, sort etc.
        if ($filterObj) {
            $relationTable = $this->filterRelationTable($relationTables, $filterObj);
        } else {
            $relationTable = $relationTables->first();
        }
        return $relationTable;
    }


    /**
     * Filter relation table info
     *
     * @param \Illuminate\Support\Collection $relationTables
     * @param CustomViewColumn|CustomViewSort|CustomViewFilter|CustomViewSummary|CustomViewGridFilter $filterObj
     * @return RelationTable|null filtered Relation Table
     */
    protected function filterRelationTable($relationTables, $filterObj): ?RelationTable
    {
        // if only 1, return first.
        if ($relationTables->count() <= 1) {
            return $relationTables->first();
        }

        ///// get relationTables filtering info
        // get condition params
        list($order_table_id, $order_column_id, $this_table_id, $this_column_id) = $this->getConditionParams($filterObj);
        // get search type
        // If $this_column_id is not "parent_id", set searchtype is select_table
        if (!isMatchString($this_column_id, Define::PARENT_ID_NAME)) {
            $searchType = SearchType::SELECT_TABLE;
        } else {
            // get parent and child relation table
            $relation = CustomRelation::getRelationByParentChild($order_table_id, $this_table_id);
            if (!$relation) {
                return null;
            }
            $searchType = (RelationType::ONE_TO_MANY == $relation->relation_type ? SearchType::ONE_TO_MANY : SearchType::MANY_TO_MANY);
        }

        return $relationTables->first(function ($relationTable) use ($this_column_id, $searchType) {
            // filtering $searchType
            $isMatchSearchType = false;
            if (isMatchString($relationTable->searchType, $searchType)) {
                $isMatchSearchType = true;
            }
            // if $searchType is SELECT_TABLE and SUMMARY_SELECT_TABLE
            elseif ($searchType == SearchType::SELECT_TABLE && $relationTable->searchType == SearchType::SUMMARY_SELECT_TABLE) {
                $isMatchSearchType = true;
            }
            if (!$isMatchSearchType) {
                return false;
            }

            // if select table, filtering selectTablePivotColumn
            if (isMatchString($searchType, SearchType::SELECT_TABLE)) {
                $selectTablePivotColumn = $relationTable->selectTablePivotColumn;
                if (!$selectTablePivotColumn || !isMatchString($selectTablePivotColumn->id, $this_column_id)) {
                    return false;
                }
            }

            return true;
        });
    }


    /**
     * Set join in query.
     *
     * @param RelationTable $relationTable relation table's info
     * @param CustomTable $whereCustomTable parent custom table
     * @return void
     */
    protected function setJoin($relationTable, $whereCustomTable)
    {
        // first, join table if needs
        if (!$this->isJoinedTable($relationTable)) {
            // If summary view and target is child, call as left join
            if (SearchType::isSummarySearchType($relationTable->searchType)) {
                // set summary joins array. After all select and group by columns, call these.
                $this->summaryJoins[] = function () use ($relationTable, $whereCustomTable) {
                    $relationTable->setSummaryChildJoin($this->query, [
                        'parent_table' => $this->custom_table,
                        'child_table' => $whereCustomTable,
                        'custom_column' => $relationTable->selectTablePivotColumn,
                        'leftJoin' => true,
                    ]);
                };
            } else {
                $relationTable->setParentJoin($this->query, [
                    'child_table' => $this->custom_table,
                    'parent_table' => $whereCustomTable,
                    'custom_column' => $relationTable->selectTablePivotColumn,
                    'leftJoin' => true,
                ]);
            }

            $this->joinedTables[] = $relationTable;
        }
    }

    /**
     * Is already setted join table
     *
     * @param RelationTable $relationTable
     * @return boolean is already joined
     */
    protected function isJoinedTable($relationTable)
    {
        return collect($this->joinedTables)->contains(function ($joinedTable) use ($relationTable) {
            return isMatchString($joinedTable->table->id, $relationTable->table->id) &&
            isMatchString($joinedTable->base_table->id, $relationTable->base_table->id) &&
            isMatchString($joinedTable->searchType, $relationTable->searchType) &&
            isMatchString($joinedTable->selectTablePivotColumn, $relationTable->selectTablePivotColumn);
        });
    }


    /**
     * Is join workflow status
     *
     * @return boolean is join workflow status
     */
    protected function isJoinWorkflowStatus($custom_view_filter): bool
    {
        return $this->isJoinWorkflow($custom_view_filter, SystemColumn::WORKFLOW_STATUS);
    }

    /**
     * Is join workflow work user
     *
     * @return boolean is join workflow status
     */
    protected function isJoinWorkflowWorkUsers($custom_view_filter): bool
    {
        return $this->isJoinWorkflow($custom_view_filter, SystemColumn::WORKFLOW_WORK_USERS);
    }

    /**
     * Is join workflow work user
     *
     * @return boolean is join workflow status
     */
    protected function isJoinWorkflow($custom_view_filter, $key): bool
    {
        // Whether custom_view_filter is boolelan. if true, always call.
        if ($custom_view_filter === true) {
        } else {
            if (!($custom_view_filter instanceof CustomViewFilter || $custom_view_filter instanceof CustomViewColumn)) {
                return false;
            }
            if ($custom_view_filter->view_column_type != ConditionType::WORKFLOW) {
                return false;
            }

            $enum = SystemColumn::getEnum($key);
            if ($custom_view_filter->view_column_target_id != $enum->option()['id']) {
                return false;
            }
        }

        if (in_array($key, $this->joinedWorkflows)) {
            return false;
        }

        $this->joinedWorkflows[] = $key;
        return true;
    }


    /**
     * Get condition params
     *
     * @param CustomViewColumn|CustomViewSort|CustomViewFilter|CustomViewSummary|CustomViewGridFilter|Notify|null $column
     * @return array
     *  offset0 : target column's table id
     *  offset1 : target column's id
     *  offset2 : this table's id
     *  offset2 : this column's id
     */
    protected function getConditionParams($column): array
    {
        if ($column instanceof CustomViewColumn || $column instanceof CustomViewFilter || $column instanceof CustomViewSort || $column instanceof CustomViewSummary || $column instanceof CustomViewGridFilter) {
            return [
                $column->view_column_table_id,
                $column->view_column_target_id,
                array_get($column, 'options.view_pivot_table_id') ?? $column->view_column_table_id,
                array_get($column, 'options.view_pivot_column_id') ?? $column->view_column_target_id,
            ];
        } elseif ($column instanceof Notify) {
            $notify_target_table_id = array_get($column, 'trigger_settings.notify_target_table_id');
            $notify_target_column = array_get($column, 'trigger_settings.notify_target_column');
            if (empty($notify_target_table_id) && isset($notify_target_column)) {
                $custom_column = CustomColumn::getEloquent($notify_target_column);
                $notify_target_table_id = $custom_column->custom_table_id;
            }
            return [
                $notify_target_table_id,
                $notify_target_column,
                array_get($column, 'trigger_settings.view_pivot_table_id'),
                array_get($column, 'trigger_settings.view_pivot_column_id'),
            ];
        }

        return [null, null, null, null];
    }

    /**
     * Get column item
     *
     * @param CustomViewColumn|CustomViewSort|CustomViewFilter|CustomViewSummary|CustomViewGridFilter|Notify $column
     * @return mixed
     */
    protected function getColumnItem($column)
    {
        if ($column instanceof Notify) {
            return $column->schedule_date_column_item;
        }

        return $column->column_item;
    }

    /**
     * Pass the names of the variables that should be serialised here
     */
    public function __sleep()
    {
        return [];
    }
}
