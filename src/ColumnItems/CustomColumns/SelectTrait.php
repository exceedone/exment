<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

trait SelectTrait
{
    public function getSelectFilterQuery($query, $input)
    {
        return $query->whereInArrayString($this->index(), $input);
    }
}
