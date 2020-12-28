<?php

namespace Exceedone\Exment\DataItems\Grid\Summary;

class SummaryOption
{
    /**
     * target table name
     *
     * @var string
     */
    protected $db_table_name;

    /**
     * Select query
     *
     * @var array
     */
    protected $selects = [];
    
    /**
     * Filter queries
     *
     * @var array
     */
    protected $filters = [];
    
    /**
     *
     * @var array
     */
    protected $subGroupbys = [];

    /**
     *
     * @var array
     */
    protected $select_groups = [];

    public function __construct(array $options)
    {
        $this->db_table_name = array_get($options, 'table_name');
        $this->filters = [array_get($options, 'filter')];
    }

    /**
     * Add database query filter
     *
     * @param mixed $filter
     * @return $this
     */
    public function addFilter($filter)
    {
        $this->filters[] = $filter;
        return $this;
    }

    /**
     * Add select query
     *
     * @param mixed $select
     * @return $this
     */
    public function addSelect($select)
    {
        $this->selects[] = $select;
        return $this;
    }

    /**
     * Add select group
     *
     * @param mixed $select_group
     * @return $this
     */
    public function addSelectGroup($select_group)
    {
        $this->select_groups[] = $select_group;
        return $this;
    }

    /**
     * Add select group
     *
     * @param mixed $select_group
     * @return $this
     */
    public function addSubGroupby($subGroupby)
    {
        $this->subGroupbys[] = $subGroupby;
        return $this;
    }

    
    public function getSelects()
    {
        return array_filter($this->selects);
    }
    public function getFilters()
    {
        return array_filter($this->filters);
    }
    public function getTableName()
    {
        return $this->db_table_name;
    }
    public function getSelectGroups()
    {
        return array_filter($this->select_groups);
    }
    public function getSelectGroupBys()
    {
        return array_filter($this->subGroupbys);
    }
}
