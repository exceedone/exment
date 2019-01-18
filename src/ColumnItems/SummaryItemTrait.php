<?php

namespace Exceedone\Exment\ColumnItems;

use Exceedone\Exment\Enums\SummaryCondition;

trait SummaryItemTrait
{

    //for summary  --------------------------------------------------

    /**
     * get sqlname for summary
     */
    protected function getSummarySqlName(){
        $db_table_name = getDBTableName($this->custom_column->custom_table);
        $column_name = $this->custom_column->column_name;

        $summary_condition = SummaryCondition::getEnum(array_get($this->options, 'summary_condition'), SummaryCondition::SUM)->lowerKey();

        $raw = "$summary_condition($db_table_name.value->'$.$column_name') AS ".$this->sqlAsName();
        return \DB::raw($raw);
    }
    
    protected function sqlAsName(){
        return "column_".array_get($this->options, 'summary_index');
    }
}
