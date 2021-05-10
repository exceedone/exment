<?php
namespace Exceedone\Exment\Services\Search;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\RelationTable;
use Exceedone\Exment\Model\Condition;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\RelationType;

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
        if($this->isAppendSelect){
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
        if(!$this->alreadyAppendSelect){
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
     * @return \Illuminate\Database\Eloquent\Collection|static[]
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
            $value, $operator, func_num_args() === 2
        );
        
        // If custom column, execute where custom column's exists query.
        if($column instanceof CustomColumn)
        {
            return $this->whereCustomColumn($column, $operator, $value, $boolean);
        }
        if(is_string($column)){
            return $this->whereCustomColumn(CustomColumn::getEloquent($column, $this->custom_table), $operator, $value, $boolean);
        }

        $this->query->where($column, $operator, $value, $boolean);
        return $this;
    }

    
    /**
     * Add an "order by" clause to the query.
     * If CustomColumn, and linkage(relation or select table), add where exists query.
     *
     * @param  \Closure|\Illuminate\Database\Query\Builder|\Illuminate\Database\Query\Expression|string  $column
     * @param  string  $direction
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function orderBy($column, $direction = 'asc')
    {
        // If custom column, execute where custom column's exists query.
        if($column instanceof CustomColumn)
        {
            return $this->orderByCustomColumn($column, $direction);
        }
        if(is_string($column)){
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
     * @return $this
     */
    protected function whereCustomColumn(CustomColumn $column, $operator = null, $value = null, $boolean = 'and')
    {
        $whereCustomTable = $column->custom_table_cache;
        if(isMatchString($whereCustomTable->id, $this->custom_table->id))
        {
            $this->query->where($column->getQueryKey(), $operator, $value, $boolean);
            return $this;
        }

        // get RelationTable info.
        $relationTable = $this->getRelationTable($whereCustomTable);

        if(!$relationTable){
            $this->query->whereNotMatch();
        }
        // set relation query using relation type class.
        else{
            $this->setJoin($relationTable, $whereCustomTable);

            // Add where query
            $this->query->where($column->getQueryKey(), $operator, $value, $boolean);
        }

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
        if(isMatchString($whereCustomTable->id, $this->custom_table->id))
        {
            $this->query->orderBy($column->getQueryKey(), $direction);
            return $this;
        }

        // get RelationTable info.
        $relationTable = $this->getRelationTable($whereCustomTable);

        if(!$relationTable){
            $this->query->whereNotMatch();
        }
        elseif($relationTable->searchType == SearchType::MANY_TO_MANY){
            throw new \Exception('Many to many relation not support order by.');
        }
        // set relation query using relation type class.
        else{
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
        // get condition params
        list($order_table_id, $order_column_id, $this_table_id, $this_column_id) = $this->getConditionParams($column);
        $orderCustomTable = CustomTable::getEloquent($order_table_id);

        // if not match this table and order table, setJoin relation table.
        if(!isMatchString($order_table_id, $this->custom_table->id))
        {
            // get RelationTable info.
            $relationTable = $this->getRelationTable($orderCustomTable, $column);

            if(!$relationTable){
                $this->query->whereNotMatch();
            }
            elseif($relationTable->searchType == SearchType::MANY_TO_MANY){
                throw new \Exception('Many to many relation not support order by.');
            }
            // set relation query using relation type class.
            else{
                $this->setJoin($relationTable, $orderCustomTable);

                // set database unique name
                $column_item = $column->column_item;
                if (!isset($column_item)) {
                    $this->query->whereNotMatch();
                    return $this;
                }
                $column_item->setUniqueTableName($relationTable->tableUniqueName);
            }
        }

        // set sort info.
        $condition_item = $column->condition_item;
        if ($condition_item) {
            $condition_item->setQuerySort($this->query, $column);
        }

        return $this;
    }


    /**
     * Get relation table info
     *
     * @param CustomTable $whereCustomTable
     * @return RelationTable relation table info
     */
    protected function getRelationTable($whereCustomTable, $filterObj = null){
        // get RelationTable info.
        $relationTables = RelationTable::getRelationTables($whereCustomTable, false, [
            'search_enabled_only' => false,
        ])->filter(function($relationTable){
            return isMatchString($relationTable->table->id, $this->custom_table->id);
        });

        // filter RelationTable, using custom view filter, sort etc.
        if($filterObj){
            $relationTable = $this->filterRelationTable($relationTables, $filterObj);
        }
        else{
            $relationTable = $relationTables->first();
        }
        return $relationTable;
    }


    /**
     * Filter relation table info
     *
     * @param \Illuminate\Support\Collection $relationTables
     * @param CustomViewSort $filterObj
     * @return RelationTable|null filtered Relation Table
     */
    protected function filterRelationTable($relationTables, $filterObj) : ?RelationTable
    {
        // if only 1, return first.
        if($relationTables->count() <= 1){
            return $relationTables->first();
        }

        ///// get relationTables filtering info
        // get condition params
        list($order_table_id, $order_column_id, $this_table_id, $this_column_id) = $this->getConditionParams($filterObj);
        // get search type
        // If $this_column_id is not "parent_id", set searchtype is select_table
        if(!isMatchString($this_column_id, Define::PARENT_ID_NAME)){
            $searchType = SearchType::SELECT_TABLE;
        }
        else{
            // get parent and child relation table
            $relation = CustomRelation::getRelationByParentChild($order_table_id, $this_table_id);
            if(!$relation){
                return null;
            }
            $searchType = (RelationType::ONE_TO_MANY == $relation->relation_type ? SearchType::ONE_TO_MANY : SearchType::MANY_TO_MANY);
        }

        return $relationTables->first(function($relationTable) use($this_column_id, $searchType){
            // filtering $searchType
            if(!isMatchString($relationTable->searchType, $searchType)){
                return false;
            }

            // if select table, filtering selectTablePivotColumn
            if(isMatchString($searchType, SearchType::SELECT_TABLE)){
                $selectTablePivotColumn = $relationTable->selectTablePivotColumn;
                if(!$selectTablePivotColumn || !isMatchString($selectTablePivotColumn->id, $this_column_id)){
                    return false;
                }
            }

            return true;
        });

        return $relationTables->first();
    }


    /**
     * Set join in query.
     *
     * @param RelationTable $relationTable relation table's info
     * @param CustomTable $whereCustomTable parent custom table
     * @return void
     */
    protected function setJoin($relationTable, $whereCustomTable){
        // first, join table if needs
        if(!$this->isJoinedTable($relationTable)){
            $relationTable->setParentJoin($this->query, [
                'parent_table' => $whereCustomTable,
                'child_table' => $this->custom_table,
                'custom_column' => $relationTable->selectTablePivotColumn,
            ]);

            $this->joinedTables[] = $relationTable;
        }
    }

    /**
     * Is already setted join table
     *
     * @param RelationTable $relationTable
     * @return boolean is already joined
     */
    protected function isJoinedTable($relationTable){
        return collect($this->joinedTables)->contains(function($joinedTable) use($relationTable){
            return isMatchString($joinedTable->table->id, $relationTable->table->id) && 
            isMatchString($joinedTable->searchType, $relationTable->searchType) && 
            isMatchString($joinedTable->selectTablePivotColumn, $relationTable->selectTablePivotColumn);
        });
    }


    /**
     * Get condition params
     *
     * @param CustomViewFilter|CustomViewSort|Condition $column
     * @return array 
     *  offset0 : target column's table id
     *  offset1 : target column's id
     *  offset2 : this table's id
     *  offset2 : this column's id
     */
    protected function getConditionParams($column) : array
    {

        if($column instanceof CustomViewFilter || $column instanceof CustomViewSort){
            return [
                $column->view_column_table_id, 
                $column->view_column_target_id,
                array_get($column, 'options.view_pivot_table_id') ?? $column->view_column_table_id,
                array_get($column, 'options.view_pivot_column_id') ?? $column->view_column_target_id,
            ];
        }

        return [null, null, null, null];
    }
}
