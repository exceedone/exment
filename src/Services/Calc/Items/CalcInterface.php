<?php

namespace Exceedone\Exment\Services\Calc\Items;

/**
 * Calc service. column calc, js, etc...
 */
interface CalcInterface
{
    // @phpstan-ignore-next-line
    public function type();
    // @phpstan-ignore-next-line
    public function text();
    // @phpstan-ignore-next-line
    public function val();
    // @phpstan-ignore-next-line
    public function displayText();
    // @phpstan-ignore-next-line
    public static function setCalcCustomColumnOptions($options, $id, $custom_table);
}
