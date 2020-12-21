<?php
namespace Exceedone\Exment\Services\ViewFilter;

use Exceedone\Exment\Enums\FilterOption;

abstract class NotNullBase extends NullBase
{
    protected function isNull() : bool{
        return false;
    }
}
