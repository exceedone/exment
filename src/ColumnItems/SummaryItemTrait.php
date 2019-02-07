<?php

namespace Exceedone\Exment\ColumnItems;

use Exceedone\Exment\Enums\SummaryCondition;

trait SummaryItemTrait
{

    //for summary  --------------------------------------------------

    /**
     * get sqlname for summary
     */
    protected function getSummarySqlName()
    {
        $db_table_name = getDBTableName($this->custom_column->custom_table);
        $column_name = $this->custom_column->column_name;

        $summary_option = array_get($this->options, 'summary_condition');
        $summary_condition = is_null($summary_option)? '': SummaryCondition::getEnum($summary_option)->lowerKey();
        $raw = "$summary_condition(json_unquote($db_table_name.value->'$.$column_name')) AS ".$this->sqlAsName();

        return \DB::raw($raw);
    }
    
    protected function sqlAsName()
    {
        return "column_".array_get($this->options, 'summary_index');
    }

    public function getGroupName()
    {
        $db_table_name = getDBTableName($this->custom_column->custom_table);
        $column_name = $this->custom_column->column_name;

        $summary_condition = SummaryCondition::getGroupCondition(array_get($this->options, 'summary_condition'));
        $alter_name = $this->sqlAsName();
        $raw = "$summary_condition($alter_name) AS $alter_name";

        return \DB::raw($raw);
    }
}
