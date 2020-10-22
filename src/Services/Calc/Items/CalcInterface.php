<?php
namespace Exceedone\Exment\Services\Calc\Items;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;

/**
 * Calc service. column calc, js, etc...
 */
interface CalcInterface
{
    public function type();
    public function text();
    public function val();
    public function displayText();
    public static function setCalcCustomColumnOptions($options, $id, $custom_table);
}
