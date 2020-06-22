<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

trait SelectTrait
{
    public function getSelectFilterQuery($query, $input)
    {
        $index = \DB::getQueryGrammar()->wrap($this->index());
        $queryStr = "FIND_IN_SET(?, REPLACE(REPLACE(REPLACE(REPLACE($index, '[', ''), ' ', ''), ']', ''), '\\\"', ''))";

        if (is_null($input)) {
            return;
        } elseif (is_list($input)) {
            $query->where(function ($query) use ($queryStr, $input) {
                foreach ($input as $i) {
                    $query->orWhereRaw($queryStr, $i);
                }
            });
        } else {
            $query->whereRaw($queryStr, $input);
        }
    }
}
