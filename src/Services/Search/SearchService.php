<?php
namespace Exceedone\Exment\Services\Search;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\RelationTable;
use Exceedone\Exment\Enums\SearchType;

/**
 * Custom Value's Search model
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
            $db_table_name = getDBTableName($this->custom_table);
            $this->query->select("$db_table_name.*");
        }
        return $this->query;
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
     * Add a where custom column. Contains CustomColumn or string.
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
            // first, join table if needs
            if(!$this->isJoinedTable($relationTable)){
                RelationTable::setParentJoin($this->query, $relationTable->searchType, [
                    'parent_table' => $whereCustomTable,
                    'child_table' => $this->custom_table,
                ]);

                $this->joinedTables[] = $relationTable;
            }
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
            // first, join table if needs
            if(!$this->isJoinedTable($relationTable)){
                RelationTable::setParentJoin($this->query, $relationTable->searchType, [
                    'parent_table' => $whereCustomTable,
                    'child_table' => $this->custom_table,
                ]);

                $this->joinedTables[] = $relationTable;
            }
            // Add orderBy query
            $this->query->orderBy($column->getQueryKey(), $direction);
        }

        return $this;
    }


    /**
     * Get relation table info
     *
     * @param CustomTable $whereCustomTable
     * @return boolean is already joined
     */
    protected function getRelationTable($whereCustomTable){
        // get RelationTable info.
        $relationTable = RelationTable::getRelationTables($whereCustomTable, false, [
            'search_enabled_only' => false,
        ])->filter(function($relationTable){
            return isMatchString($relationTable->table->id, $this->custom_table->id);
        })->first();
        return $relationTable;
    }


    /**
     * Is already setted join table
     *
     * @param RelationTable $relationTable
     * @return boolean is already joined
     */
    protected function isJoinedTable($relationTable){
        return collect($this->joinedTables)->contains(function($joinedTable) use($relationTable){
            return isMatchString($joinedTable->custom_table->id, $relationTable->table->id) && 
                isMatchString($joinedTable->searchType, $relationTable->searchType);
        });
    }
}
