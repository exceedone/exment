<?php
namespace Exceedone\Exment\Services\Calc\Items;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;

/**
 * Calc service. column calc, js, etc...
 */
abstract class ItemBase implements CalcInterface
{
    public function __construct(?CustomColumn $custom_column, ?CustomTable $custom_table){
        $this->custom_column = $custom_column;
        $this->custom_table = $custom_table;
    }
    
    public function displayText(){
        $text = $this->text();
        return '${' . $text . '}';
    }

    public function toArray(){
        return [
            'custom_column' => $this->custom_column,
            'formula_column' => $this->custom_column ? $this->custom_column->column_name : null,
            'val' => $this->val(),
            'type' => $this->type(),
            'displayText' => $this->displayText(),
        ];
    }
}
