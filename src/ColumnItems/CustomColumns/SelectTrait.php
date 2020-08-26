<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

trait SelectTrait
{
    public function getSelectFilterQuery($query, $input)
    {
        return $query->whereInArrayString($this->index(), $input);
    }

    public function isMultipleEnabled()
    {
        return boolval(array_get($this->custom_column, 'options.multiple_enabled', false));
    }
}
