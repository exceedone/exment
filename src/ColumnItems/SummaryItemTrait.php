<?php

namespace Exceedone\Exment\ColumnItems;

use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\GroupCondition;

trait SummaryItemTrait
{

    //for summary  --------------------------------------------------

    /**
     * get sqlname for summary
     */
    protected function getSummarySqlName()
    {
        extract($this->getSummaryParams());

        $summary_option = array_get($this->options, 'summary_condition');
        $summary_condition = is_null($summary_option) ? null : SummaryCondition::getEnum($summary_option)->lowerKey();
        
        if (isset($summary_condition)) {
            $raw = "$summary_condition($value_column) AS ".$this->sqlAsName();
        } 
        elseif(isset($group_format)){
            $raw = "DATE_FORMAT($value_column, '$group_format') AS ".$this->sqlAsName();
        }
        else {
            $raw = "$value_column AS ".$this->sqlAsName();
        }

        return \DB::raw($raw);
    }
    
    /**
     * get sqlname for summary
     */
    protected function getGroupBySqlName()
    {
        extract($this->getSummaryParams());
        
        if (isset($group_format)) {
            $raw = "DATE_FORMAT($value_column, '$group_format')";
        }
        else {
            $raw = "$value_column";
        }

        return \DB::raw($raw);
    }

    protected function getSummaryParams(){
        $db_table_name = getDBTableName($this->custom_column->custom_table);
        $column_name = $this->custom_column->column_name;

        $group_option = array_get($this->options, 'group_condition');
        $group_format = is_null($group_option)? null : GroupCondition::getOption(['name' => $group_option])['sqlformat'];

        // get value_column
        $json_column = \DB::getQueryGrammar()->wrapJsonUnquote("$db_table_name.value->$column_name");
        $value_column = ($this->custom_column->index_enabled) ? $this->index() : $json_column;
        
        return [
            'db_table_name' => $db_table_name,
            'column_name' => $column_name,
            'group_option' => $group_option,
            'group_format' => $group_format,
            'json_column' => $json_column,
            'value_column' => $value_column,
        ];
    }
    
    protected function sqlAsName()
    {
        return "column_".array_get($this->options, 'summary_index');
    }

    public function getGroupName()
    {
        $db_table_name = getDBTableName($this->custom_column->custom_table);
        $column_name = $this->custom_column->column_name;

        $summary_condition = SummaryCondition::getSummaryCondition(array_get($this->options, 'summary_condition'));
        $alter_name = $this->sqlAsName();
        $raw = "$summary_condition($alter_name) AS $alter_name";

        return \DB::raw($raw);
    }
}
